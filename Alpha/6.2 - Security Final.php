/**
 * Plugin Name: TW365 Enterprise IP Access Control (Snippet Version)
 * Description: 企業級緊急 IP 存取管控系統 (v6.2)。修復 IP 偽造風險，整合 Cloudflare 支援與現代化 UX。
 * Version: 6.2 - Security Final
 * Author: Gemini AI & Grok (Co-developed)
 */

if ( ! class_exists( 'TW365_Security_Lockdown' ) ) {

    class TW365_Security_Lockdown {

        private const OPTION_NAME = 'tw365_security_whitelist_v6';
        private const NONCE_ACTION = 'tw365_save_ip_rules';

        public function __construct() {
            // Priority 1: 越早攔截越好，減少伺服器負載
            add_action( 'init', array( $this, 'enforce_access_control' ), 1 );
            add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        }

        /**
         * 核心：執行 IP 存取限制邏輯
         */
        public function enforce_access_control() {
            
            // 1. 效能優化：如果是 AJAX 或 Cron，直接放行 (避免讀取資料庫)
            // 學長 Note: 把這段移到最上面，因為這些請求頻率最高，不需要浪費資源去查白名單
            if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
                return;
            }

            // 2. 取得白名單
            $whitelist = get_option( self::OPTION_NAME, array() );
            if ( empty( $whitelist ) ) {
                return;
            }

            // 3. 判斷是否為受保護路徑 (後台或登入頁)
            $request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $is_login_page = ( stripos( $request_path, 'wp-login.php' ) !== false );
            $is_admin_area = is_admin();

            if ( $is_admin_area || $is_login_page ) {
                
                $visitor_ip = $this->get_client_ip();
                $access_granted = false;

                // 4. 比對白名單
                foreach ( $whitelist as $rule ) {
                    if ( $this->check_ip_match( $visitor_ip, $rule['ip'] ) ) {
                        $access_granted = true;
                        break;
                    }
                }

                // 5. 阻擋存取
                if ( ! $access_granted ) {
                    $this->deny_access( $visitor_ip );
                }
            }
        }

        /**
         * 取得用戶端真實 IP (Security Hardened)
         * 學長 Note: 這是 v6.2 最重要的修改。
         * 我們不能盲目信任 HTTP_X_FORWARDED_FOR，因為駭客可以在 Header 裡隨便填。
         */
        private function get_client_ip() {
            
            // A. 優先檢查 Cloudflare (HTTP_CF_CONNECTING_IP)
            // Cloudflare 是受信任的代理，它會覆蓋這個 Header，駭客無法偽造
            if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
                return $_SERVER['HTTP_CF_CONNECTING_IP'];
            }

            // B. 嚴格模式：預設不信任 X-Forwarded-For
            // 除非你很確定你的主機在 Load Balancer 後面，否則不要開啟下面這段
            /*
            if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
                return trim( reset( $ips ) );
            }
            */

            // C. 預設回傳 REMOTE_ADDR (最安全)
            // 這是 TCP 連線層級的 IP，無法被 HTTP Header 偽造
            return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        }

        /**
         * IP 比對邏輯 (支援 CIDR)
         */
        private function check_ip_match( $client_ip, $rule_ip ) {
            // CIDR 網段比對
            if ( strpos( $rule_ip, '/' ) !== false ) {
                $parts = explode( '/', $rule_ip );
                if ( count( $parts ) !== 2 ) return false;

                $subnet = $parts[0];
                $bits = $parts[1];

                // IPv4 CIDR 運算
                if ( filter_var( $client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && 
                     filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    
                    $ip_long = ip2long( $client_ip );
                    $subnet_long = ip2long( $subnet );
                    $mask = -1 << ( 32 - $bits );
                    return ( $ip_long & $mask ) === ( $subnet_long & $mask );
                }
                return false; // 暫不支援 IPv6 CIDR 數學運算
            }

            // 單一 IP 比對
            return $client_ip === $rule_ip;
        }

        /**
         * 阻擋並回應 403
         */
        private function deny_access( $ip ) {
            if ( ! headers_sent() ) {
                header( 'HTTP/1.1 403 Forbidden' );
                header( 'Cache-Control: no-cache, must-revalidate' );
            }
            
            // 顯示原始 REMOTE_ADDR 方便除錯
            $debug_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown';

            wp_die(
                sprintf(
                    '<h1>🛑 存取被拒絕 (Access Denied)</h1>' .
                    '<p>您的 IP (<strong>%s</strong>) 未在授權名單中。</p>' .
                    '<hr><p style="font-size:12px; color:#666;">Debug Info: Real IP detected as %s. <br>If you are the admin, please connect via an authorized network.</p>',
                    esc_html( $ip ),
                    esc_html( $debug_ip )
                ),
                'Security Checkpoint',
                array( 'response' => 403 )
            );
        }

        /**
         * 註冊控制台 Widget
         */
        public function register_dashboard_widget() {
            if ( ! current_user_can( 'manage_options' ) ) return;

            wp_add_dashboard_widget(
                'tw365_security_widget',
                '🛡️ TW365 緊急 IP 管控中心 (v6.2 Final)',
                array( $this, 'render_widget' )
            );
        }

        /**
         * 渲染 Widget 介面
         */
        public function render_widget() {
            $message = '';
            $current_rules = get_option( self::OPTION_NAME, array() );

            // --- 處理表單提交 ---
            if ( isset( $_POST['tw365_submit'] ) ) {
                
                // 1. CSRF 檢查
                if ( ! check_admin_referer( self::NONCE_ACTION, 'tw365_nonce_field' ) ) {
                    echo '<div class="notice notice-error"><p>安全性權杖過期。</p></div>';
                    return;
                }

                // 2. 數學驗證
                $user_ans = isset( $_POST['math_ans'] ) ? intval( $_POST['math_ans'] ) : -1;
                $real_ans = isset( $_POST['math_expected'] ) ? intval( $_POST['math_expected'] ) : -2;

                // 準備暫存資料 (回填用)
                $temp_ips = isset( $_POST['ips'] ) ? $_POST['ips'] : array();
                $temp_notes = isset( $_POST['notes'] ) ? $_POST['notes'] : array();
                $temp_rules = array();
                foreach( $temp_ips as $k => $v ) {
                    $temp_rules[] = array( 'ip' => trim($v), 'note' => sanitize_text_field( $temp_notes[$k] ) );
                }

                if ( $user_ans === $real_ans ) {
                    $valid_rules = array();
                    $error_count = 0;

                    foreach ( $temp_rules as $rule ) {
                        if ( empty( $rule['ip'] ) ) continue;
                        
                        // 格式檢查 helper
                        if ( $this->validate_format( $rule['ip'] ) ) {
                            $valid_rules[] = $rule;
                        } else {
                            $error_count++;
                        }
                    }

                    update_option( self::OPTION_NAME, $valid_rules );
                    $current_rules = $valid_rules;

                    $msg_class = ( $error_count > 0 ) ? 'warning' : 'success';
                    $msg_text = ( $error_count > 0 ) ? "已儲存，但過濾了 {$error_count} 筆格式錯誤 IP。" : "✅ 白名單更新成功，防護已啟動。";
                    $message = "<div class='notice notice-{$msg_class} inline'><p>{$msg_text}</p></div>";
                } else {
                    $current_rules = $temp_rules; // 保留輸入
                    $message = '<div class="notice notice-error inline"><p>❌ 數學驗證錯誤，設定未儲存。</p></div>';
                }
            }

            // --- UI 顯示 ---
            $n1 = rand( 3, 9 ); $n2 = rand( 2, 9 ); $expected = $n1 * $n2;
            $display_rules = empty( $current_rules ) ? array( array( 'ip' => '', 'note' => '' ) ) : $current_rules;
            $client_ip = $this->get_client_ip();

            echo $message;
            ?>
            <div class="tw365-widget-wrap">
                <style>
                    .tw365-row { display: flex; gap: 5px; margin-bottom: 8px; }
                    .tw365-row input[name="ips[]"] { flex: 2; font-family: monospace; }
                    .tw365-row input[name="notes[]"] { flex: 3; }
                    .tw365-math { background: #f0f0f1; padding: 10px; border-left: 4px solid #72aee6; margin-top: 15px; }
                    .tw365-ip-display { cursor: pointer; padding: 2px 5px; background: #e5e5e5; border-radius: 3px; }
                    .tw365-ip-display:hover { background: #dcdcde; }
                </style>

                <form method="post" action="">
                    <?php wp_nonce_field( self::NONCE_ACTION, 'tw365_nonce_field' ); ?>
                    
                    <p><strong>授權名單：</strong> <span class="description">支援單一 IP 或 CIDR (192.168.0.0/24)</span></p>

                    <div id="tw365_rows">
                        <?php foreach ( $display_rules as $rule ) : ?>
                            <div class="tw365-row">
                                <input type="text" name="ips[]" value="<?php echo esc_attr( $rule['ip'] ); ?>" placeholder="IP 地址">
                                <input type="text" name="notes[]" value="<?php echo esc_attr( $rule['note'] ); ?>" placeholder="備註">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button" onclick="tw365_add()">+ 增加欄位</button>

                    <div class="tw365-math">
                        <strong>🔒 安全驗證：</strong> <?php echo "{$n1} × {$n2} = ?"; ?>
                        <input type="number" name="math_ans" style="width:60px;" required>
                        <input type="hidden" name="math_expected" value="<?php echo $expected; ?>">
                    </div>

                    <p class="submit">
                        <input type="submit" name="tw365_submit" class="button button-primary" value="儲存設定">
                    </p>
                </form>

                <p style="text-align:right; font-size:12px; color:#50575e;">
                    目前 IP: <code class="tw365-ip-display" title="點擊複製"><?php echo esc_html( $client_ip ); ?></code>
                </p>

                <script>
                function tw365_add() {
                    const div = document.createElement('div');
                    div.className = 'tw365-row';
                    div.innerHTML = '<input type="text" name="ips[]" placeholder="IP"><input type="text" name="notes[]" placeholder="備註">';
                    document.getElementById('tw365_rows').appendChild(div);
                }

                // 現代化 Clipboard 支援
                document.querySelector('.tw365-ip-display')?.addEventListener('click', async function() {
                    try {
                        await navigator.clipboard.writeText(this.innerText);
                        const originalColor = this.style.backgroundColor;
                        this.style.backgroundColor = '#00a32a'; // Green flash
                        this.style.color = '#fff';
                        setTimeout(() => {
                            this.style.backgroundColor = originalColor;
                            this.style.color = '';
                        }, 500);
                    } catch (err) { alert('IP: ' + this.innerText); }
                });
                </script>
            </div>
            <?php
        }

        private function validate_format( $input ) {
            if ( strpos( $input, '/' ) !== false ) {
                $parts = explode( '/', $input );
                return count( $parts ) === 2 && filter_var( $parts[0], FILTER_VALIDATE_IP ) && is_numeric( $parts[1] ) && $parts[1] >= 0 && $parts[1] <= 128;
            }
            return filter_var( $input, FILTER_VALIDATE_IP );
        }
    }

    new TW365_Security_Lockdown();
}