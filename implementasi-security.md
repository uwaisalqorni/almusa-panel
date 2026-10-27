# Security Hardening & Anti-PHP2Shell / Webshell Intrusion Detection Plan (`implementasi-security.md`)

## 1. Threat Model: PHP2Shell & Webshell Attacks
A PHP2Shell / Webshell attack occurs when an attacker uploads or injects a malicious PHP script into a hosted website (`/www/wwwroot/<domain>`) to execute arbitrary OS commands (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`), read sensitive files outside the document root (`../../../etc/passwd` or `/www/panel/data/panel.db`), or maintain obfuscated backdoor persistence (`eval(base64_decode(...))`, `gzinflate`, `str_rot13`, dynamic variable functions).

Because **Almusa Panel** itself requires `exec()` in its CLI worker (`task.php`), we must strictly separate **Panel CLI configuration** from **Hosted Website PHP-FPM Pools**.

---

## 2. Layer 1: Hardened PHP-FPM Pool & Nginx Isolation (Prevention)

Every website created via `SiteController.php` and `scripts/vhost_manager.sh` MUST enforce strict runtime isolation inside its dedicated PHP-FPM pool (`/etc/php/<ver>/fpm/pool.d/<domain>.conf`) and Nginx virtual host (`/etc/nginx/sites-available/<domain>.conf`):

### A. Per-Site PHP-FPM Pool Hardening (`plugins/php/conf/fpm_pool.conf`)
1. **Strict `open_basedir` Jail:**
   Lock each website strictly to its own document root and isolated temp directory so it can NEVER read `/www/panel`, `/etc`, or other websites under `/www/wwwroot/`:
   ```ini
   php_admin_value[open_basedir] = /www/wwwroot/{{DOMAIN}}/:/tmp/:/proc/
   ```
2. **Disable Dangerous PHP2Shell Functions by Default:**
   Automatically disable OS command execution and low-level process functions in all hosted FPM pools:
   ```ini
   php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_waitpid,pcntl_wexitstatus,pcntl_wifexited,pcntl_wifsignaled,pcntl_wifstopped,pcntl_wstopsig,pcntl_wtermsig,apache_setenv,putenv,ini_alter,dl,readlink,symlink,link,syslog
   ```
   *(Note: Provide a toggle in the Panel UI per-site settings to selectively allow `proc_open` or `pcntl_*` ONLY if the developer explicitly enables "Composer/Artisan CLI Mode", while keeping `system`, `passthru`, and `shell_exec` blocked).*
3. **Disable Remote Code Inclusion & Expose PHP:**
   ```ini
   php_admin_flag[allow_url_fopen] = on
   php_admin_flag[allow_url_include] = off
   php_admin_flag[expose_php] = off
   php_admin_flag[display_errors] = off
   php_admin_flag[log_errors] = on
   php_admin_value[error_log] = /www/server/logs/{{DOMAIN}}_php_error.log
   ```

### B. Nginx Anti-Webshell Execution Rules (`plugins/nginx/conf/vhost_template.conf`)
1. **Block PHP Execution in Upload/Public Storage Directories:**
   Prevent uploaded shells (e.g., `avatar.jpg.php` or `shell.php` inside upload folders) from ever reaching PHP-FPM:
   ```nginx
   # Deny PHP execution inside common upload and storage directories
   location ~* ^/(uploads|storage|public/uploads|wp-content/uploads|assets/uploads)/.*\.(php|php5|phtml|phar|inc)$ {
       deny all;
       return 403;
   }
   ```
2. **Mini-WAF Request Filtering (Block Common PHP2Shell Payloads in URI/Query):**
   ```nginx
   # Block base64_encode/eval injection and directory traversal in query strings
   if ($query_string ~* "(base64_encode|base64_decode|eval\(|gzinflate|passthru|shell_exec|php://input|php://filter|\.\./\.\./)") {
       return 403;
   }
   ```

---

## 3. Layer 2: Database Schema for Security Center (`data/panel.db`)

Add the following tables to `app/Core/Database.php` migration to track security scans, quarantined files, and real-time intrusion alerts:

```sql
-- 7. Security Alerts & Intrusion Events (IDS Log)
CREATE TABLE IF NOT EXISTS security_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,                -- 'webshell_detected', 'waf_blocked', 'file_integrity', 'bruteforce'
    severity TEXT NOT NULL DEFAULT 'high',   -- 'low', 'medium', 'high', 'critical'
    domain TEXT DEFAULT 'system',
    file_path TEXT DEFAULT NULL,
    attacker_ip TEXT DEFAULT NULL,
    matched_rule TEXT NOT NULL,              -- e.g., "Obfuscated eval(base64_decode)"
    snippet TEXT DEFAULT NULL,               -- Redacted code/request snippet
    status TEXT DEFAULT 'unread',            -- 'unread', 'resolved', 'quarantined', 'ignored'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 8. Quarantined Suspicious Files
CREATE TABLE IF NOT EXISTS quarantine_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_path TEXT NOT NULL,
    quarantine_path TEXT NOT NULL,           -- Stored in /www/server/quarantine/ (chmod 000)
    file_hash TEXT NOT NULL,                 -- SHA-256 hash
    matched_signature TEXT NOT NULL,
    quarantined_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 4. Layer 3: Built-in PHP2Shell & Malware Scanner (`app/Core/SecurityScanner.php` & `scripts/webshell_scanner.sh`)

Implement an automated and on-demand heuristic scanner that inspects `/www/wwwroot/` for PHP webshells and backdoors:

### Heuristic Signatures to Detect:
1. **Obfuscated Execution Chains:**
   Regex matching `/(eval|assert|create_function|include|require)\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin|pack)\s*\(/i`
2. **Direct Shell Execution in Web Files:**
   Regex matching `/\b(shell_exec|passthru|system|proc_open|popen|pcntl_exec)\s*\(\s*(\$_(GET|POST|REQUEST|COOKIE|SERVER)|base64_decode)/i`
3. **Known Webshell Signatures:**
   Regex matching `/\b(FilesMan|WSO_VERSION|b374k|r57shell|c99shell|Alfa_Shell|IndoXploit|mini_shell|p0wny)\b/i`
4. **Superglobal Variable Function Calls (Dynamic Backdoors):**
   Regex matching `/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i`
5. **Suspicious File Permissions & Newly Modified PHP Files in Upload Folders:**
   Detect any `.php`, `.phtml`, or `.phar` file located inside `/uploads/` or `/storage/` directories, or files with `0777` permissions.

### Scanner Features in Panel UI (`SecurityController.php` & `views/security/index.php`):
1. **1-Click Full Scan (`Scan Now`):** Runs via `task.php` in the background, scans all `/www/wwwroot/*` directories, and logs findings into `security_events`.
2. **Real-Time Nginx Access/Error Log Monitor:** Parses `/var/log/nginx/access.log` and per-site error logs for HTTP `403` WAF blocks, `open_basedir` violation warnings, or `disable_functions` execution attempts (which immediately reveals if an attacker tried to run a shell command and got blocked by PHP-FPM).
3. **1-Click Quarantine & Restore:**
   - **Quarantine:** Moves the infected file from `/www/wwwroot/...` to `/www/server/quarantine/<sha256>.quarantine` with `chmod 000` so it can never be executed, and records it in `quarantine_files`.
   - **Whitelist / Ignore:** Marks false-positive vendor files as safe by SHA-256 hash.
   - **Delete Permanently:** Securely removes the file.
4. **Brute-Force Protection on Panel Login:**
   - Lock out an IP address for 15 minutes after 5 failed login attempts on the Almusa Panel login page and record a `bruteforce` event in `security_events`.

---

## 5. Implementation Steps for the AI Agent

1. **Update Database Migration (`app/Core/Database.php`):** Add the `security_events` and `quarantine_files` tables.
2. **Create `app/Core/SecurityScanner.php` & `scripts/webshell_scanner.sh`:** Implement heuristic PHP2Shell scanning, Nginx/PHP-FPM log violation parser (`disable_functions` & `open_basedir` alerts), and Quarantine manager (`/www/server/quarantine`).
3. **Create `app/Controllers/SecurityController.php` & `views/security/index.php`:**
   - Build a dedicated **"Security Center (Anti-Webshell & IDS)"** menu in the panel sidebar.
   - Display Security Score badge, Active Protections status (`open_basedir` jail, `disable_functions` guard, Nginx Anti-Shell WAF, Login Brute-Force Guard), Threat Log table, and 1-Click **"Scan All Websites for PHP2Shell"** button.
4. **Harden `plugins/php/conf/fpm_pool.conf` & `plugins/nginx/conf/vhost_template.conf`:** Ensure all newly created sites automatically get the `open_basedir` jail, `disable_functions` list, and Nginx upload-directory PHP execution block.