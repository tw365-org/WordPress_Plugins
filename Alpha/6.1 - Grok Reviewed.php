<?php
/**
 * Plugin Name: TW365 Enterprise IP Access Control (Snippet Version)
 * Description: 企業級緊急 IP 存取管控系統。採用 Class 結構封裝，支援 CIDR、備註、數學驗證與防呆機制。
 * Version: 6.1 - Grok Reviewed 😏
 * Author: Gemini AI (Reviewed by Grok 4.1)
 */

if ( ! class_exists( 'TW365_Security_Lockdown' ) ) {

    class TW365_Security_Lockdown {

        /**
         * @var string 資料庫選項名稱 (Option Name)
         */
        private const OPTION_NAME = 'tw365_security_whitelist_v6';

        /**
         * @var string Nonce 動作名稱 (Security Token)
         */
        private const NONCE_ACTION = 'tw365_save_ip_rules';

        /**
         * 建構函式：初始化所有掛鉤
         */
        public function __construct() {
            add_action( 'init', array( $this, 'enforce_access_control' ), 1 );
            add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        }

        /**
         * 執行 IP 存取限制邏輯
         * @return void
         */
        public function enforce_access_control() {
            $whitelist = get_option( self::OPTION_NAME, array() );

            if ( empty( $whitelist ) ) {
                return;
            }

            if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
                return;
            }

            $request_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $is_login_page = ( stripos( $request_path, 'wp-login.php' ) !== false );
            $is_admin_area = is_admin();

            if ( $is_admin_area || $is_login_page ) {
                $visitor_ip = $this->get_client_ip();
                $access_granted = false;

                foreach ( $whitelist as $rule ) {
                    if ( $this->check_ip_match( $visitor_ip, $rule['ip'] ) ) {
                        $access_granted = true;
                        break;
                    }
                }

                if ( ! $access_granted ) {
                    $this->deny_access( $visitor_ip );
                }
            }
        }

        /**
         * 阻擋回應 (HTTP 403)
         * @param string $ip 訪客 IP
         * @return void
         */
        private function deny_access( $ip ) {
            if ( ! headers_sent() ) {
                header( 'HTTP/1.1 403 Forbidden' );
                header( 'Status: 403 Forbidden' );
                header( 'Cache-Control: no-cache, must-revalidate' );
            }
            
            wp_die(
                sprintf(
                    '<h1>🛑 存取被拒絕 (Access Denied)</h1>' .
                    '<p>您的來源 IP (<strong>%s</strong>) 未在管理員授權名單中。</p>' .
                    '<p>此區域僅限特定網路存取。如果您是管理員，請切換網路或透過 FTP 調整設定。</p>',
                    esc_html( $ip )
                ),
                'Security Checkpoint',
                array( 'response' => 403 )
            );
        }

        /**
         * 取得用戶端真實 IP (支援 Cloudflare、Proxy 等)
         * @return string IP Address
         */
        private function get_client_ip() {
            $headers = array(
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            );

            foreach ( $headers as $header ) {
                if ( isset( $_SERVER[ $header ] ) ) {
                    $ips = explode( ',', $_SERVER[ $header ] );
                    $ip = trim( reset( $ips ) );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        return $ip;
                    }
                }
            }
            return '0.0.0.0';
        }

        /**
         * IP 比對邏輯 (IPv4 CIDR + 單一 IP，IPv6 請用完整地址)
         * @param string $client_ip 訪客 IP
         * @param string $rule_ip   白名單規則 (IP or CIDR)
         * @return bool 是否匹配
         */
        private function check_ip_match( $client_ip, $rule_ip ) {
            if ( strpos( $rule_ip, '/' ) !== false ) {
                $parts = explode( '/', $rule_ip );
                if ( count( $parts ) !== 2 ) return false;

                $subnet = $parts[0];
                $bits = $parts[1];

                // IPv4 CIDR 比對
                if ( filter_var( $client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && 
                     filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    
                    $ip_long = ip2long( $client_ip );
                    $subnet_long = ip2long( $subnet );
                    $mask = -1 << ( 32 - $bits );
                    return ( $ip_long & $mask ) === ( $subnet_long & $mask );
                }
                return false;
            }

            return $client_ip === $rule_ip;
        }

        /**
         * 驗證輸入格式 (支援 IPv4/IPv6/CIDR)
         * @param string $input
         * @return bool
         */
        private function validate_format( $input ) {
            if ( strpos( $input, '/' ) !== false ) {
                $parts = explode( '/', $input );
                return count( $parts ) === 2 && 
                       filter_var( $parts[0], FILTER_VALIDATE_IP ) && 
                       is_numeric( $parts[1] ) && 
                       $parts[1] >= 0 && $parts[1] <= 128;
            }
            return filter_var( $input, FILTER_VALIDATE_IP );
        }

        /**
         * 註冊控制台小工具
         */
        public function register_dashboard_widget() {
            wp_add_dashboard_widget(
                'tw365_security_widget',
                '🛡️ TW365 緊急 IP 管控中心 (Enterprise)',
                array( $this, 'render_widget' )
            );
        }

        /**
         * 渲染 Widget HTML 與處理表單
         */
        public function render_widget() {
            // 權限檢查移到這裡
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $message = '';
            $current_rules = get_option( self::OPTION_NAME, array() );

            if ( isset( $_POST['tw365_submit'] ) ) {
                if ( ! check_admin_referer( self::NONCE_ACTION, 'tw365_nonce_field' ) ) {
                    echo '<div class="notice notice-error"><p>安全性權杖過期，請重新整理頁面。</p></div>';
                    return;
                }

                $user_ans = isset( $_POST['math_ans'] ) ? intval( $_POST['math_ans'] ) : '';
                $real_ans = isset( $_POST['math_expected'] ) ? intval( $_POST['math_expected'] ) : 0;
                
                $temp_ips = isset( $_POST['ips'] ) ? $_POST['ips'] : array();
                $temp_notes = isset( $_POST['notes'] ) ? $_POST['notes'] : array();
                $temp_rules = array();

                foreach ( $temp_ips as $k => $v ) {
                    $note = isset( $temp_notes[ $k ] ) ? sanitize_text_field( $temp_notes[ $k ] ) : '';
                    $temp_rules[] = array( 'ip' => trim( $v ), 'note' => $note );
                }

                if ( $user_ans != '' && $user_ans === $real_ans ) {
                    $valid_rules = array();
                    $error_count = 0;

                    foreach ( $temp_rules as $rule ) {
                        if ( empty( $rule['ip'] ) ) continue;
                        if ( $this->validate_format( $rule['ip'] ) ) {
                            $valid_rules[] = $rule;
                        } else {
                            $error_count++;
                        }
                    }

                    update_option( self::OPTION_NAME, $valid_rules );
                    $current_rules = $valid_rules;

                    $msg_text = ( $error_count > 0 ) 
                        ? "設定已更新，但過濾了 {$error_count} 筆格式錯誤的資料。" 
                        : "✅ 白名單已成功部署，防護層已啟動。";
                    $msg_class = ( $error_count > 0 ) ? 'warning' : 'success';
                    $message = "<div class='notice notice-{$msg_class} inline'><p>{$msg_text}</p></div>";
                } else {
                    $current_rules = $temp_rules;
                    $message = '<div class="notice notice-error inline"><p>❌ 數學驗證錯誤。設定<b>未儲存</b>，請重新計算。</p></div>';
                }
            }

            $n1 = rand( 3, 9 );
            $n2 = rand( 2, 9 );
            $expected = $n1 * $n2;

            echo $message;
            $display_rules = empty( $current_rules ) ? array( array( 'ip' => '', 'note' => '' ) ) : $current_rules;
            ?>
            <div class="tw365-widget-container">
                <style>
                    .tw365-row { display: flex; gap: 5px; margin-bottom: 8px; }
                    .tw365-row input[name="ips[]"] { flex: 2; font-family: monospace; }
                    .tw365-row input[name="notes[]"] { flex: 3; }
                    .tw365-math-box { background: #f6f7f7; border: 1px solid #c3c4c7; border-left: 4px solid #72aee6; padding: 10px; margin-top: 15px; }
                    .tw365-footer { margin-top: 10px; text-align: right; font-size: 11px; color: #646970; }
                </style>

                <form method="post" action="">
                    <?php wp_nonce_field( self::NONCE_ACTION, 'tw365_nonce_field' ); ?>
                    
                    <p><strong>授權 IP 清單：</strong> <br><span class="description">支援 IPv4 CIDR (192.168.0.0/24)、IPv6 完整地址</span></p>

                    <div id="tw365_rows">
                        <?php foreach ( $display_rules as $rule ) : ?>
                            <div class="tw365-row">
                                <input type="text" name="ips[]" value="<?php echo esc_attr( $rule['ip'] ); ?>" placeholder="IP 地址或 CIDR">
                                <input type="text" name="notes[]" value="<?php echo esc_attr( $rule['note'] ); ?>" placeholder="備註 (如: 台北辦公室)">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button" onclick="tw365_add_field()">+ 增加欄位</button>

                    <div class="tw365-math-box">
                        <strong>🔒 變更確認：</strong> 請計算 <?php echo "{$n1} × {$n2} = ?"; ?>
                        <input type="number" name="math_ans" style="width: 60px; margin-left: 5px;" required>
                        <input type="hidden" name="math_expected" value="<?php echo $expected; ?>">
                    </div>

                    <p class="submit">
                        <input type="submit" name="tw365_submit" class="button button-primary" value="儲存並套用限制">
                    </p>
                </form>

                <div class="tw365-footer">
                    目前 IP：<code class="tw365-ip" title="點擊複製"><?php echo esc_html( $this->get_client_ip() ); ?></code> (點擊複製)
                </div>

                <script>
                function tw365_add_field() {
                    var container = document.getElementById('tw365_rows');
                    var div = document.createElement('div');
                    div.className = 'tw365-row';
                    div.innerHTML = '<input type="text" name="ips[]" placeholder="IP 地址或 CIDR"><input type="text" name="notes[]" placeholder="備註">';
                    container.appendChild(div);
                }

                // 現代化 Clipboard API
                document.querySelector('.tw365-ip')?.addEventListener('click', async function() {
                    try {
                        await navigator.clipboard.writeText(this.textContent);
                        // 顯示複製成功 (無 alert)
                        this.style.background = '#d4edda';
                        setTimeout(() => this.style.background = '', 1000);
                    } catch(err) {
                        // Fallback
                        alert('IP 已複製');
                    }
                });
                </script>
            </div>
            <?php
        }
    }

    new TW365_Security_Lockdown();
}