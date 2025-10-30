<?php
/**
 * Plugin Name: BookingPress QR Code Extension
 * Description: 為 BookingPress 添加 QR Code 生成和核銷功能
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 */

// 防止直接訪問
if (!defined('ABSPATH')) {
    exit;
}

// 檢查 BookingPress 是否已激活
add_action('plugins_loaded', 'check_bookingpress_dependency');

function check_bookingpress_dependency() {
    if (!class_exists('BookingPress_Core')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>BookingPress QR Code Extension 需要 BookingPress 插件才能運行。</p></div>';
        });
        return;
    }

    // 載入主要功能
    require_once plugin_dir_path(__FILE__) . 'includes/class-qrcode-extension.php';
    require_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';
}

// 插件激活時的處理
register_activation_hook(__FILE__, 'bookingpress_qrcode_activate');

function bookingpress_qrcode_activate() {
    global $wpdb;

    // 創建獨立的 QR Code 表
    $table_name = $wpdb->prefix . 'bookingpress_qrcodes';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        booking_id bigint(20) NOT NULL,
        verification_code varchar(50) NOT NULL,
        qrcode_url varchar(500) DEFAULT NULL,
        qrcode_data text DEFAULT NULL,
        status varchar(20) DEFAULT 'active',
        verified_at datetime DEFAULT NULL,
        verified_by bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY verification_code (verification_code),
        KEY booking_id (booking_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);
    
    if ($result) {
        error_log('BookingPress QR Code: 成功創建 QR Code 表');
    } else {
        error_log('BookingPress QR Code: 創建 QR Code 表失敗');
    }

    // 創建上傳目錄
    $upload_dir = wp_upload_dir();
    $qr_dir = $upload_dir['basedir'] . '/bookingpress-qrcodes/';

    if (!file_exists($qr_dir)) {
        wp_mkdir_p($qr_dir);

        // 添加 .htaccess 保護
        $htaccess_content = "Options -Indexes\n";
        file_put_contents($qr_dir . '.htaccess', $htaccess_content);
    }
}

// 插件停用時的處理
register_deactivation_hook(__FILE__, 'bookingpress_qrcode_deactivate');

function bookingpress_qrcode_deactivate() {
    // 清理臨時文件（可選）
    // 注意：不刪除 QR Code 表和文件，以防用戶重新啟用
}

// 插件卸載時的處理（完全移除）
register_uninstall_hook(__FILE__, 'bookingpress_qrcode_uninstall');

function bookingpress_qrcode_uninstall() {
    global $wpdb;
    
    // 刪除 QR Code 表
    $table_name = $wpdb->prefix . 'bookingpress_qrcodes';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    
    // 清理上傳的 QR Code 文件
    $upload_dir = wp_upload_dir();
    $qr_dir = $upload_dir['basedir'] . '/bookingpress-qrcodes/';
    
    if (file_exists($qr_dir)) {
        // 刪除目錄中的所有文件
        $files = glob($qr_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        // 刪除目錄
        rmdir($qr_dir);
    }
    
    error_log('BookingPress QR Code: 插件已完全卸載，所有數據已清理');
}
?>