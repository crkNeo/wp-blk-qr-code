<?php
/**
 * BookingPress QR Code 功能擴展
 * 在預訂完成時自動生成 QR Code 用於現場核銷
 */

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

class BookingPress_QRCode_Extension
{

    public function __construct()
    {
        // 使用 BookingPress 官方提供的 hook (推薦)
        add_action('bookingpress_after_add_appointment_from_backend', array($this, 'generate_qrcode_after_appointment'), 10, 3);

        // 其他備用 hooks - 不使用會干擾 AJAX 回應的 hooks
        add_action('bookingpress_after_booking_save', array($this, 'generate_qrcode_after_booking'), 10, 3);
        add_action('bookingpress_payment_completed', array($this, 'generate_qrcode_after_booking'), 10, 3);

        // 註冊 WordPress cron 任務來處理延遲的 QR code 生成
        add_action('bookingpress_generate_qr_delayed', array($this, 'delayed_qr_generation'));

        // 添加前端調試腳本
        add_action('wp_footer', array($this, 'add_debug_script'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_debug_scripts'));

        // 在 my-bookings 頁面顯示 QR Code (安全版本)
        add_action('bookingpress_modify_booking_data_for_myBookings_data', array($this, 'add_qrcode_to_booking_data'), 999, 2);

        // 不再需要修改原本的 BookingPress 表

        // 重新啟用腳本載入
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * 使用官方 hook 生成 QR Code (推薦方法)
     */
    public function generate_qrcode_after_appointment($inserted_booking_id, $bookingpress_appointment_data, $entry_id)
    {
        $this->console_log("官方 Hook 觸發: bookingpress_after_add_appointment_from_backend");
        $this->console_log("預訂 ID: {$inserted_booking_id}, Entry ID: {$entry_id}");

        // 調用通用的 QR Code 生成方法
        $this->generate_qrcode_after_booking($inserted_booking_id, $bookingpress_appointment_data, array());
    }

    /**
     * 預訂完成後生成 QR Code (通用方法)
     */
    public function generate_qrcode_after_booking($booking_id, $entry_details, $payment_gateway_data)
    {
        global $wpdb, $BookingPress;

        if (empty($booking_id)) {
            return;
        }

        // 記錄到瀏覽器 console
        $this->console_log("開始為預訂 ID {$booking_id} 生成 QR Code");
        $this->console_log("Hook 參數 - booking_id: {$booking_id}");

        // 生成唯一的核銷碼
        $verification_code = $this->generate_verification_code($booking_id);

        // QR Code 內容只使用驗證碼，簡單明瞭
        $qr_content = $verification_code;

        // 生成 QR Code 圖片
        $qr_code_url = $this->generate_qr_code_image($qr_content, $booking_id);

        if ($qr_code_url) {
            // 儲存到獨立的 QR Code 表
            $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';

            $qr_data = array(
                'booking_id' => $booking_id,
                'verification_code' => $verification_code,
                'timestamp' => current_time('timestamp'),
                'site_url' => site_url()
            );

            $insert_result = $wpdb->insert(
                $qr_table,
                array(
                    'booking_id' => $booking_id,
                    'verification_code' => $verification_code,
                    'qrcode_url' => $qr_code_url,
                    'qrcode_data' => json_encode($qr_data),
                    'status' => 'active',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s')
            );

            if ($insert_result) {
                $this->console_log("成功儲存 QR Code 記錄，預訂 ID: {$booking_id}");

                // 發送包含 QR Code 的郵件給客戶
                $this->send_qr_code_email($booking_id, $verification_code, $qr_code_url);
            } else {
                $this->console_log("儲存 QR Code 記錄失敗，預訂 ID: {$booking_id}");
                $this->console_log("資料庫錯誤: " . $wpdb->last_error);
            }
        } else {
            $this->console_log("QR Code 圖片生成失敗，預訂 ID: {$booking_id}");
        }
    }

    /**
     * 發送包含 QR Code 的郵件給客戶
     */
    private function send_qr_code_email($booking_id, $verification_code, $qr_code_url)
    {
        global $wpdb;

        $this->console_log("準備發送 QR Code 郵件，預訂 ID: {$booking_id}");

        // 獲取預訂資訊
        $entries_table = $wpdb->prefix . 'bookingpress_entries';
        $booking = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$entries_table} WHERE bookingpress_entry_id = %d",
                $booking_id
            )
        );

        if (!$booking) {
            $this->console_log("找不到預訂記錄，無法發送郵件");
            return false;
        }

        // 獲取客戶郵箱
        $customer_email = $booking->bookingpress_customer_email;
        if (empty($customer_email)) {
            $this->console_log("客戶郵箱為空，無法發送郵件");
            return false;
        }

        // 準備郵件內容
        $customer_name = $booking->bookingpress_customer_name;
        $service_name = $booking->bookingpress_service_name;
        $appointment_date = $booking->bookingpress_appointment_date;
        $appointment_time = $booking->bookingpress_appointment_time;

        // 郵件主題
        $subject = '您的預約 QR Code - ' . $service_name;

        // 郵件標頭
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // 郵件內容（HTML）
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #ffffff; padding: 30px; border: 1px solid #e0e0e0; }
                .qr-section { text-align: center; margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; }
                .qr-code-img { max-width: 250px; margin: 20px auto; display: block; border: 3px solid #667eea; border-radius: 8px; }
                .info-box { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
                .verification-code { font-size: 24px; font-weight: bold; color: #667eea; letter-spacing: 2px; margin: 15px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; }
                .booking-details { background: #fff; padding: 15px; margin: 15px 0; border-radius: 6px; }
                .booking-details p { margin: 8px 0; }
                .highlight { color: #667eea; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <h1>🎉 預約成功！</h1>
                    <p>感謝您的預約，以下是您的活動 QR Code</p>
                </div>

                <div class="content">
                    <p>親愛的 <strong>' . esc_html($customer_name) . '</strong>，</p>
                    <p>您的預約已成功確認！請保存此郵件並於約定時間出示 QR Code。</p>

                    <div class="booking-details">
                        <h3 style="color: #667eea; margin-top: 0;">📋 預約資訊</h3>
                        <p><strong>服務項目：</strong> ' . esc_html($service_name) . '</p>
                        <p><strong>預約日期：</strong> ' . esc_html($appointment_date) . '</p>
                        <p><strong>預約時間：</strong> ' . esc_html($appointment_time) . '</p>
                        <p><strong>預訂編號：</strong> #' . esc_html($booking_id) . '</p>
                    </div>

                    <div class="qr-section">
                        <h3 style="color: #667eea;">您的專屬 QR Code</h3>
                        <p>請於活動當天出示此 QR Code 給服務人員掃描</p>
                        <img src="' . esc_url($qr_code_url) . '" alt="QR Code" class="qr-code-img">
                        <p style="margin-top: 20px;">驗證碼</p>
                        <div class="verification-code">' . esc_html($verification_code) . '</div>
                        <p style="font-size: 12px; color: #666; margin-top: 15px;">
                            💡 建議將此 QR Code 截圖或下載保存到手機相簿
                        </p>
                    </div>

                    <div class="info-box">
                        <p style="margin: 0;"><strong>📝 溫馨提醒：</strong></p>
                        <ul style="margin: 10px 0;">
                            <li>請提前 10-15 分鐘到達現場</li>
                            <li>請確保 QR Code 清晰可辨識</li>
                            <li>每個 QR Code 僅能使用一次</li>
                            <li>如有任何問題，請聯繫我們的客服團隊</li>
                        </ul>
                    </div>

                    <p style="margin-top: 30px;">期待與您見面！</p>
                </div>

                <div class="footer">
                    <p>此郵件由系統自動發送，請勿直接回覆</p>
                    <p style="margin-top: 10px;">© ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';

        // 發送郵件
        $mail_sent = wp_mail($customer_email, $subject, $message, $headers);

        if ($mail_sent) {
            $this->console_log("QR Code 郵件發送成功至: {$customer_email}");
        } else {
            $this->console_log("QR Code 郵件發送失敗至: {$customer_email}");
        }

        return $mail_sent;
    }

    /**
     * 生成驗證碼
     */
    private function generate_verification_code($booking_id)
    {
        return $booking_id . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
    }

    /**
     * 生成 QR Code 圖片
     */
    private function generate_qr_code_image($content, $booking_id)
    {
        // 使用 Google Charts API 或 phpqrcode 庫
        // 這裡使用 Google Charts API 作為示例

        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/bookingpress-qrcodes/';

        // 創建目錄如果不存在
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }

        // 文件名
        $filename = 'booking-' . $booking_id . '-qr.png';
        $file_path = $qr_dir . $filename;
        $file_url = $upload_dir['baseurl'] . '/bookingpress-qrcodes/' . $filename;

        // 直接使用傳入的內容作為 QR Code 內容（現在已經是驗證碼）
        $this->console_log("生成 QR Code，內容: {$content}");

        // 使用 Google Charts API
        $qr_api_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($content);

        $this->console_log("Google Charts API URL: {$qr_api_url}");

        $image_data = wp_remote_get($qr_api_url, array(
            'timeout' => 30,
            'sslverify' => false
        ));

        if (!is_wp_error($image_data) && wp_remote_retrieve_response_code($image_data) === 200) {
            $image_content = wp_remote_retrieve_body($image_data);

            // 檢查是否真的是圖片內容
            if (!empty($image_content) && strlen($image_content) > 100) {
                // 檢查是否是 PNG 圖片或其他圖片格式
                $is_png = substr($image_content, 0, 8) === "\x89PNG\r\n\x1a\n";
                $is_gif = substr($image_content, 0, 6) === "GIF87a" || substr($image_content, 0, 6) === "GIF89a";
                $is_jpeg = substr($image_content, 0, 3) === "\xFF\xD8\xFF";

                if ($is_png || $is_gif || $is_jpeg) {
                    file_put_contents($file_path, $image_content);
                    $this->console_log("Google Charts API 成功生成 QR Code");
                    return $file_url;
                } else {
                    $this->console_log("Google Charts 返回的不是有效的圖片格式");
                    $this->console_log("前 20 字節: " . bin2hex(substr($image_content, 0, 20)));
                }
            }
        }

        $this->console_log("Google Charts API 失敗，嘗試備用方案");

        // 如果 Google API 失敗，使用備用 API
        return $this->generate_simple_qr_fallback($content, $file_path, $file_url);
    }

    /**
     * 簡單的備用 QR Code 生成方案
     */
    private function generate_simple_qr_fallback($content, $file_path, $file_url)
    {
        // 嘗試使用其他免費 QR Code API
        $backup_apis = array(
            'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($content),
            'https://qr-code-generator.com/api/qr-code?size=300x300&data=' . urlencode($content)
        );

        $this->console_log("嘗試備用 QR Code API");

        foreach ($backup_apis as $api_url) {
            $this->console_log("嘗試 API: {$api_url}");

            $image_data = wp_remote_get($api_url, array(
                'timeout' => 15,
                'sslverify' => false
            ));

            if (!is_wp_error($image_data) && wp_remote_retrieve_response_code($image_data) === 200) {
                $image_content = wp_remote_retrieve_body($image_data);
                if (!empty($image_content) && strlen($image_content) > 100) {
                    file_put_contents($file_path, $image_content);
                    $this->console_log("備用 API 成功生成 QR Code");
                    return $file_url;
                }
            }
        }

        $this->console_log("所有 QR Code API 都失敗");
        return false;
    }

    /**
     * 創建文字版本的 QR Code 替代方案
     */
    private function create_text_qr_fallback($content, $file_path, $file_url)
    {
        // 創建一個簡單的文字圖片作為最後備用方案
        if (function_exists('imagecreate')) {
            $img = imagecreate(300, 300);
            $bg = imagecolorallocate($img, 255, 255, 255);
            $text_color = imagecolorallocate($img, 0, 0, 0);

            // 解析 JSON 內容獲取驗證碼
            $data = json_decode($content, true);
            $verification_code = isset($data['verification_code']) ? $data['verification_code'] : 'ERROR';

            // 添加文字
            imagestring($img, 5, 50, 100, 'QR Code', $text_color);
            imagestring($img, 3, 30, 130, 'Verification Code:', $text_color);
            imagestring($img, 4, 50, 150, $verification_code, $text_color);
            imagestring($img, 2, 20, 200, 'Please show this to staff', $text_color);

            imagepng($img, $file_path);
            imagedestroy($img);

            return $file_url;
        }

        return false;
    }

    /**
     * 在 AJAX 預訂完成後生成 QR Code
     * 已停用 - 避免干擾預約流程
     */
    public function generate_qr_after_ajax_booking()
    {
        // 不再使用此函數 - 已從 constructor 中移除此 hook
        // 保留函數定義避免錯誤
    }

    /**
     * 延遲的 QR Code 生成
     */
    public function delayed_qr_generation()
    {
        global $wpdb;

        $this->console_log('開始延遲 QR Code 生成');

        // 使用 bookingpress_entries 表（正確的表名）
        $entries_table = $wpdb->prefix . 'bookingpress_entries';
        $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';

        // 查找最近 5 分鐘內的預訂，且沒有 QR Code 的
        $recent_bookings = $wpdb->get_results(
            "SELECT b.bookingpress_entry_id as bookingpress_booking_id, b.bookingpress_customer_name
             FROM {$entries_table} b
             LEFT JOIN {$qr_table} q ON b.bookingpress_entry_id = q.booking_id
             WHERE b.bookingpress_created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             AND q.booking_id IS NULL
             ORDER BY b.bookingpress_entry_id DESC
             LIMIT 10"
        );

        if ($recent_bookings) {
            $this->console_log('找到 ' . count($recent_bookings) . ' 個需要生成 QR Code 的預訂');

            foreach ($recent_bookings as $booking) {
                $this->console_log("為預訂 ID {$booking->bookingpress_booking_id} (客戶: {$booking->bookingpress_customer_name}) 生成 QR Code");
                $this->generate_qrcode_after_booking($booking->bookingpress_booking_id, array(), array());
            }
        } else {
            $this->console_log('沒有找到需要生成 QR Code 的預訂');
        }
    }

    /**
     * 將 QR Code 調試信息添加到 BookingPress AJAX response
     * 已停用 - 避免干擾預約流程
     */
    public function add_qr_debug_to_response($response, $posted_data)
    {
        // 不再修改回應，避免干擾預約流程
        return $response;
    }

    /**
     * 在 my-bookings 頁面添加 QR Code 數據
     */
    public function add_qrcode_to_booking_data($bookings_data, $booking_id)
    {
        // 從獨立的 QR Code 表獲取資料
        if (empty($bookings_data) || !is_array($bookings_data)) {
            return $bookings_data;
        }

        global $wpdb;

        try {
            $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';

            // 檢查 QR Code 表是否存在
            static $table_checked = false;
            static $table_exists = false;

            if (!$table_checked) {
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$qr_table}'") === $qr_table;
                $table_checked = true;
            }

            if (!$table_exists) {
                return $bookings_data;
            }

            // 安全地添加 QR Code 數據
            foreach ($bookings_data as $key => $booking) {
                if (!isset($booking['bookingpress_booking_id'])) {
                    continue;
                }

                $current_booking_id = intval($booking['bookingpress_booking_id']);

                if ($current_booking_id <= 0) {
                    continue;
                }

                // 從獨立的 QR Code 表查詢
                $qr_data = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT verification_code, qrcode_url, status, verified_at 
                         FROM {$qr_table} 
                         WHERE booking_id = %d 
                         AND status = 'active'
                         ORDER BY created_at DESC 
                         LIMIT 1",
                        $current_booking_id
                    )
                );

                // 只在有 QR Code 數據時才添加
                if ($qr_data) {
                    $bookings_data[$key]['qrcode_url'] = $qr_data->qrcode_url;
                    $bookings_data[$key]['verification_code'] = $qr_data->verification_code;
                    $bookings_data[$key]['qr_status'] = $qr_data->status;
                    $bookings_data[$key]['verified_at'] = $qr_data->verified_at;
                }
            }
        } catch (Exception $e) {
            // 靜默處理錯誤，不影響原始功能
            error_log('BookingPress QR Code Extension (Independent Table): ' . $e->getMessage());
        }

        return $bookings_data;
    }

    /**
     * 獲取 QR Code 表名
     */
    public function get_qr_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'bookingpress_qrcodes';
    }

    /**
     * 檢查 QR Code 表是否存在
     */
    public function qr_table_exists()
    {
        global $wpdb;
        $table_name = $this->get_qr_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * 輸出調試訊息到瀏覽器 console
     */
    private $console_messages = array();

    public function console_log($message)
    {
        $this->console_messages[] = '[BookingPress QR Code] ' . $message;
    }

    /**
     * 在頁面底部添加 console.log 腳本
     */
    public function add_debug_script()
    {
        if (!empty($this->console_messages)) {
            echo '<script>';
            foreach ($this->console_messages as $message) {
                echo 'console.log(' . json_encode($message) . ');';
            }
            echo '</script>';

            // 清空訊息，避免重複輸出
            $this->console_messages = array();
        }
    }

    /**
     * 載入調試腳本
     */
    public function enqueue_debug_scripts()
    {
        // 在所有頁面載入調試腳本
        wp_enqueue_script(
            'bookingpress-qrcode-debug',
            plugins_url('../assets/js/qrcode-debug.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );
    }

    /**
     * 載入腳本和樣式
     */
    public function enqueue_scripts()
    {
        // 只在管理後台或特定頁面載入，避免全域衝突
        if (is_admin()) {
            return;
        }

        global $post;

        // 安全檢查
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // 只在 my-bookings 頁面載入 QR Code 顯示腳本
        if (is_page() && has_shortcode($post->post_content, 'bookingpress_my_appointments')) {
            wp_enqueue_style(
                'bookingpress-qrcode-style',
                plugins_url('../assets/css/qrcode-style.css', __FILE__),
                array(),
                '1.0.0'
            );

            wp_enqueue_script(
                'bookingpress-qrcode-script',
                plugins_url('../assets/js/qrcode-script.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );

            // 準備要傳遞給 JavaScript 的數據
            $booking_data_for_js = array();

            // 直接查詢 BookingPress 預訂表
            global $wpdb;
            $current_user_id = get_current_user_id();

            if ($current_user_id > 0) {
                // 直接從 BookingPress 資料表查詢當前用戶的預訂
                $bookings_table = $wpdb->prefix . 'bookingpress_entries';

                $my_bookings = $wpdb->get_results($wpdb->prepare(
                    "SELECT bookingpress_entry_id as bookingpress_booking_id, 
                    bookingpress_customer_name, 
                    bookingpress_service_name,
                    bookingpress_appointment_date,
                    bookingpress_appointment_time
             FROM {$bookings_table} 
             WHERE bookingpress_customer_id = %d 
             ORDER BY bookingpress_created_at DESC",
                    $current_user_id
                ), ARRAY_A);

                error_log('找到預訂數量: ' . count($my_bookings));

                if (!empty($my_bookings)) {
                    $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';

                    foreach ($my_bookings as $booking) {
                        $booking_id = $booking['bookingpress_booking_id'];

                        // 從 QR Code 表中獲取資料
                        $qrcode_data = $wpdb->get_row($wpdb->prepare(
                            "SELECT qrcode_url, verification_code, status FROM {$qr_table} WHERE booking_id = %d AND status = 'active'",
                            $booking_id
                        ));

                        if ($qrcode_data) {
                            $booking_data_for_js[$booking_id] = array(
                                'qrcode_url' => $qrcode_data->qrcode_url,
                                'verification_code' => $qrcode_data->verification_code,
                                'booking_data' => $booking
                            );
                            error_log("預訂 {$booking_id} 有 QR Code 資料");
                        } else {
                            error_log("預訂 {$booking_id} 沒有 QR Code 資料");
                        }
                    }
                } else {
                    error_log('當前用戶沒有預訂記錄');
                }
            } else {
                error_log('用戶未登入');
            }

            // 使用 wp_localize_script 將數據傳遞給 JavaScript
            wp_localize_script(
                'bookingpress-qrcode-script',
                'bookingpress_qrcode_data',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('bookingpress_qrcode_nonce'),
                    'bookings' => $booking_data_for_js,
                )
            );

            // 除錯：記錄傳遞的資料
            error_log('最終傳遞給前端的 QR Code 資料: ' . print_r($booking_data_for_js, true));
        }

        // 如果是核銷頁面，載入核銷相關腳本
        if (is_page() && has_shortcode($post->post_content, 'bookingpress_qr_verification')) {
            wp_enqueue_style(
                'bookingpress-qrcode-verification-style',
                plugins_url('../assets/css/qrcode-style.css', __FILE__),
                array(),
                '1.0.0'
            );
        }
    }
}

// 初始化類
$bookingpress_qrcode = new BookingPress_QRCode_Extension();

/**
 * QR Code 核銷 AJAX 處理函數
 */
add_action('wp_ajax_verify_booking_qrcode', 'handle_qrcode_verification');
add_action('wp_ajax_nopriv_verify_booking_qrcode', 'handle_qrcode_verification');

// 添加獲取核銷紀錄的AJAX處理
add_action('wp_ajax_get_recent_verifications', 'handle_get_recent_verifications');
add_action('wp_ajax_nopriv_get_recent_verifications', 'handle_get_recent_verifications');

function handle_qrcode_verification()
{
    global $wpdb, $BookingPress;

    // 檢查權限
    if (!current_user_can('edit_posts') && !current_user_can('manage_bookingpress_settings')) {
        wp_send_json_error('您沒有權限執行此操作');
    }

    // 驗證 nonce
    if (!wp_verify_nonce($_POST['nonce'], 'bookingpress_qrcode_nonce')) {
        wp_send_json_error('安全驗證失敗');
    }

    $verification_code = sanitize_text_field($_POST['verification_code']);

    if (empty($verification_code)) {
        wp_send_json_error('驗證碼不能為空');
    }

    // 從獨立的 QR Code 表查詢
    $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';

    $qr_record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$qr_table} 
             WHERE verification_code = %s 
             AND status = 'active'",
            $verification_code
        )
    );

    if (!$qr_record) {
        wp_send_json_error('無效的驗證碼或 QR Code 不存在');
    }

    // 檢查是否已經核銷過
    if ($qr_record->verified_at !== null) {
        wp_send_json_error('此 QR Code 已經核銷過了');
    }

    // 獲取對應的預訂資訊
    $entries_table = $wpdb->prefix . 'bookingpress_entries';
    $booking = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$entries_table} 
             WHERE bookingpress_entry_id = %d",
            $qr_record->booking_id
        )
    );

    if (!$booking) {
        wp_send_json_error('找不到對應的預訂記錄');
    }

    // 檢查預訂狀態
    if (isset($booking->bookingpress_appointment_status) && $booking->bookingpress_appointment_status === 'cancelled') {
        wp_send_json_error('此預訂已被取消，無法核銷');
    }

    // 更新 QR Code 記錄為已核銷
    $update_result = $wpdb->update(
        $qr_table,
        array(
            'verified_at' => current_time('mysql'),
            'verified_by' => get_current_user_id(),
            'updated_at' => current_time('mysql')
        ),
        array('id' => $qr_record->id),
        array('%s', '%d', '%s'),
        array('%d')
    );

    if ($update_result !== false) {
        // 記錄核銷成功
        error_log("核銷成功: 預訂ID {$qr_record->booking_id}, 驗證碼 {$verification_code}");

        $response_data = array(
            'message' => '核銷成功！',
            'booking_id' => $qr_record->booking_id,
            'verification_time' => current_time('Y-m-d H:i:s')
        );

        // 如果有預訂資訊，添加到回應中
        if (isset($booking)) {
            $response_data['customer_name'] = $booking->bookingpress_customer_name ?? '未知客戶';
            $response_data['service_name'] = $booking->bookingpress_service_name ?? '未知服務';
            $response_data['appointment_date'] = $booking->bookingpress_appointment_date ?? '未知日期';
        }

        wp_send_json_success($response_data);
    } else {
        wp_send_json_error('核銷失敗，資料庫更新錯誤');
    }
}

/**
 * 獲取最近核銷紀錄的AJAX處理函數
 */
function handle_get_recent_verifications()
{
    global $wpdb, $BookingPress;

    // 檢查權限
    if (!current_user_can('edit_posts') && !current_user_can('manage_bookingpress_settings')) {
        wp_send_json_error('您沒有權限執行此操作');
    }

    // 驗證 nonce
    if (!wp_verify_nonce($_POST['nonce'], 'bookingpress_qrcode_nonce')) {
        wp_send_json_error('安全驗證失敗');
    }

    $qr_table = $wpdb->prefix . 'bookingpress_qrcodes';
    $entries_table = $wpdb->prefix . 'bookingpress_entries';

    // 獲取最近10筆核銷紀錄
    $recent_verifications = $wpdb->get_results(
        "SELECT q.*, e.bookingpress_customer_name, e.bookingpress_service_name, e.bookingpress_appointment_date
         FROM {$qr_table} q
         LEFT JOIN {$entries_table} e ON q.booking_id = e.bookingpress_entry_id
         WHERE q.verified_at IS NOT NULL
         ORDER BY q.verified_at DESC
         LIMIT 10"
    );

    if ($recent_verifications) {
        $formatted_records = array();
        foreach ($recent_verifications as $record) {
            $formatted_records[] = array(
                'booking_id' => $record->booking_id,
                'customer_name' => $record->bookingpress_customer_name ?? '未知客戶',
                'service_name' => $record->bookingpress_service_name ?? '未知服務',
                'appointment_date' => $record->bookingpress_appointment_date ?? '未知日期',
                'verified_at' => $record->verified_at,
                'verification_code' => substr($record->verification_code, 0, 8) . '****' // 部分隱藏驗證碼
            );
        }
        wp_send_json_success($formatted_records);
    } else {
        wp_send_json_success(array());
    }
}

?>