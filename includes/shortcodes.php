<?php
/**
 * QR Code 核銷短代碼
 * 使用方法: [bookingpress_qr_verification]
 */

// 註冊核銷短代碼
add_shortcode('bookingpress_qr_verification', 'bookingpress_qr_verification_shortcode');

function bookingpress_qr_verification_shortcode($atts) {
    // 檢查權限 - 允許管理員和編輯者使用核銷功能
    if (!current_user_can('edit_posts') && !current_user_can('manage_bookingpress_settings')) {
        return '<p>您沒有權限訪問此頁面。請聯繫管理員。</p>';
    }

    // 載入必要的腳本和樣式
    wp_enqueue_script('jquery');
    wp_enqueue_script(
        'bookingpress-qrcode-verification',
        plugins_url('../assets/js/qrcode-verification.js', __FILE__),
        array('jquery'),
        '1.0.0',
        true
    );

    // 載入 Html5-QRCode 庫 (穩定且手機支援好)
    wp_enqueue_script(
        'html5-qrcode',
        'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
        array(),
        '2.3.8',
        true
    );

    // 傳遞 AJAX 參數
    wp_localize_script('bookingpress-qrcode-verification', 'bookingpress_qrcode', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('bookingpress_qrcode_nonce')
    ));

    ob_start();
    ?>
    <div class="bookingpress-qr-verification-page">
        <div class="qrcode-scanner-container">
            <h2>預訂核銷系統</h2>

            <div class="verification-methods">
                <!-- 掃描 QR Code -->
                <div class="qr-scanner-section">
                    <h3>掃描 QR Code</h3>
                    <div id="qr-reader"></div>
                    <div class="scanner-tips">
                        <small>💡 提示：將 QR Code 對準掃描框，系統將自動識別並核銷</small>
                    </div>
                </div>

                <!-- 手動輸入驗證碼 -->
                <div class="manual-verification">
                    <h3>手動輸入驗證碼</h3>
                    <div class="input-group">
                        <input type="text"
                               id="manual-verification-code"
                               placeholder="請輸入驗證碼 (例: BP00000123ABCDEF)"
                               maxlength="20"
                               autocomplete="off" />
                        <button type="button" class="btn-manual-verify">核銷</button>
                    </div>
                </div>

                <!-- 掃描結果顯示區域 -->
                <div class="verification-result" id="verification-result"></div>

                <!-- 最近核銷記錄 -->
                <div class="recent-verifications">
                    <h3>最近核銷記錄</h3>
                    <div id="recent-verifications-list">
                        <!-- 動態載入最近的核銷記錄 -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
    .bookingpress-qr-verification-page {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    /* QR Scanner 樣式 */
    .qr-scanner-section {
        margin-bottom: 30px;
        padding: 20px;
        border: 2px solid #e1e1e1;
        border-radius: 8px;
        background-color: #f8f9fa;
        text-align: center;
    }

    .qr-scanner-section h3 {
        margin: 0 0 15px 0;
        color: #333;
        font-size: 18px;
        font-weight: 600;
    }

    /* Html5-QRCode 掃描器自定義樣式 */
    #qr-reader {
        width: 100%;
        max-width: 600px;
        margin: 0 auto;
        border: 2px solid #667eea;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    #qr-reader video {
        border-radius: 10px;
    }

    #qr-reader__dashboard_section {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
        padding: 15px !important;
        border-radius: 0 0 10px 10px !important;
    }

    #qr-reader__dashboard_section button {
        background: white !important;
        color: #667eea !important;
        border: none !important;
        border-radius: 6px !important;
        padding: 10px 20px !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: all 0.3s ease !important;
    }

    #qr-reader__dashboard_section button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2) !important;
    }

    #qr-reader__dashboard_section select {
        background: white !important;
        color: #333 !important;
        border: 2px solid white !important;
        border-radius: 6px !important;
        padding: 8px 12px !important;
        font-size: 14px !important;
    }

    #qr-reader__scan_region {
        border: 3px dashed rgba(102, 126, 234, 0.8) !important;
        border-radius: 8px !important;
    }

    #qr-reader__scan_region img {
        opacity: 0.8 !important;
    }

    .scanner-tips {
        margin-top: 15px;
        padding: 10px;
        font-size: 14px;
        color: #6c757d;
        text-align: center;
        background: #f8f9fa;
        border-radius: 6px;
    }

    /* 成功通知樣式 */
    .notification-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.75);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .notification-modal {
        background: white;
        padding: 40px 30px;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        max-width: 450px;
        width: 90%;
        text-align: center;
        animation: slideIn 0.3s ease;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-30px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .notification-icon {
        font-size: 72px;
        margin-bottom: 20px;
        animation: scaleIn 0.5s ease;
    }

    @keyframes scaleIn {
        0% { transform: scale(0); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }

    .notification-title {
        font-size: 28px;
        font-weight: bold;
        color: #28a745;
        margin-bottom: 15px;
    }

    .notification-details {
        text-align: left;
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin: 20px 0;
    }

    .notification-details p {
        margin: 8px 0;
        font-size: 15px;
        color: #495057;
    }

    .notification-details strong {
        color: #212529;
        display: inline-block;
        min-width: 100px;
    }

    .notification-close-btn {
        padding: 12px 32px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        margin-top: 10px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
    }

    .notification-close-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
    }

    /* 手機優化 */
    @media (max-width: 768px) {
        .qr-scanner-section {
            padding: 15px;
        }

        #qr-scan-region {
            width: 200px;
            height: 200px;
        }

        .manual-verification {
            padding: 15px;
        }

        .input-group {
            flex-direction: column;
        }

        .input-group button {
            margin-top: 10px;
        }

        .notification-modal {
            padding: 30px 20px;
            max-width: 95%;
        }

        .notification-icon {
            font-size: 56px;
        }

        .notification-title {
            font-size: 24px;
        }

        .notification-details strong {
            min-width: 80px;
        }
    }

    .input-group input:focus {
        outline: none;
        border-color: #0073aa;
        box-shadow: 0 0 5px rgba(0, 115, 170, 0.3);
    }

    .input-group button {
        padding: 12px 24px;
        background-color: #28a745;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        transition: background-color 0.2s ease;
    }

    .input-group button:hover {
        background-color: #218838;
    }

    .input-group button:disabled {
        background-color: #6c757d;
        cursor: not-allowed;
    }

    .verification-result {
        padding: 15px;
        border-radius: 6px;
        margin: 20px 0;
        font-weight: bold;
        text-align: center;
        display: none;
    }

    .verification-result.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .verification-result.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .verification-result.info {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .booking-details {
        margin-top: 15px;
        padding: 15px;
        background-color: #f8f9fa;
        border-radius: 6px;
        text-align: left;
    }

    .booking-details h4 {
        margin: 0 0 10px 0;
        color: #495057;
    }

    .booking-details p {
        margin: 5px 0;
        color: #6c757d;
    }

    .recent-verifications {
        margin-top: 30px;
    }

    .recent-verifications h3 {
        color: #495057;
        border-bottom: 2px solid #e9ecef;
        padding-bottom: 10px;
    }

    .verification-item {
        padding: 15px;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        margin-bottom: 10px;
        background-color: #f8f9fa;
    }

    .verification-item .timestamp {
        font-size: 12px;
        color: #6c757d;
        float: right;
    }

    .verification-item .booking-info {
        color: #495057;
        font-weight: 500;
    }

    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #28a745;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-left: 10px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    @media (max-width: 768px) {
        .bookingpress-qr-verification-page {
            margin: 10px;
            padding: 15px;
        }

        .input-group {
            flex-direction: column;
        }

        .input-group button {
            margin-top: 10px;
        }
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        let isVerifying = false;

        // 手動核銷按鈕點擊事件
        $('.btn-manual-verify').on('click', function() {
            const verificationCode = $('#manual-verification-code').val().trim().toUpperCase();

            if (!verificationCode) {
                showResult('error', '請輸入驗證碼');
                return;
            }

            if (verificationCode.length < 8) {
                showResult('error', '驗證碼格式不正確');
                return;
            }

            verifyBooking(verificationCode);
        });

        // Enter 鍵觸發核銷
        $('#manual-verification-code').on('keypress', function(e) {
            if (e.which === 13 && !isVerifying) {
                $('.btn-manual-verify').click();
            }
        });

        // 輸入時自動轉換為大寫
        $('#manual-verification-code').on('input', function() {
            this.value = this.value.toUpperCase();
        });

        // 核銷功能
        function verifyBooking(verificationCode) {
            if (isVerifying) return;

            isVerifying = true;
            const $button = $('.btn-manual-verify');
            const originalText = $button.text();

            $button.prop('disabled', true).html('核銷中... <span class="loading-spinner"></span>');
            showResult('info', '正在驗證中...');

            const data = {
                action: 'verify_booking_qrcode',
                verification_code: verificationCode,
                nonce: bookingpress_qrcode.nonce
            };

            $.post(bookingpress_qrcode.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        const bookingData = response.data;
                        const successHtml = `
                            <div class="booking-details">
                                <h4>核銷成功！</h4>
                                <p><strong>預訂編號:</strong> ${bookingData.booking_id}</p>
                                <p><strong>客戶姓名:</strong> ${bookingData.customer_name}</p>
                                <p><strong>服務項目:</strong> ${bookingData.service_name}</p>
                                <p><strong>預約日期:</strong> ${bookingData.appointment_date}</p>
                            </div>
                        `;
                        showResult('success', successHtml);
                        $('#manual-verification-code').val('');

                        // 添加到最近核銷記錄
                        addToRecentVerifications(bookingData);

                        // 播放成功音效（如果瀏覽器支持）
                        playSuccessSound();

                    } else {
                        showResult('error', response.data || '核銷失敗');
                        playErrorSound();
                    }
                })
                .fail(function(xhr, status, error) {
                    showResult('error', '網絡錯誤，請檢查網絡連接後重試');
                    playErrorSound();
                })
                .always(function() {
                    isVerifying = false;
                    $button.prop('disabled', false).text(originalText);
                });
        }

        // 顯示結果
        function showResult(type, message) {
            const $result = $('#verification-result');
            $result.removeClass('success error info')
                   .addClass(type)
                   .html(message)
                   .show();

            // 自動隱藏信息類型的消息
            if (type === 'info') {
                setTimeout(function() {
                    if ($result.hasClass('info')) {
                        $result.fadeOut();
                    }
                }, 3000);
            }
        }

        // 添加到最近核銷記錄
        function addToRecentVerifications(bookingData) {
            const timestamp = new Date().toLocaleString('zh-TW');
            const itemHtml = `
                <div class="verification-item">
                    <div class="timestamp">${timestamp}</div>
                    <div class="booking-info">
                        ${bookingData.customer_name} - ${bookingData.service_name}
                        <br><small>預訂編號: ${bookingData.booking_id}</small>
                    </div>
                </div>
            `;

            $('#recent-verifications-list').prepend(itemHtml);

            // 只保留最近 10 筆記錄
            const $items = $('#recent-verifications-list .verification-item');
            if ($items.length > 10) {
                $items.slice(10).remove();
            }
        }

        // 播放成功音效
        function playSuccessSound() {
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N+QQAoUXrTp66hVFApGn+DyvmwhBSuGz/DZdycEL4nO7tqMOQcTYbbr56ZSEgxGoOHuuW8gBjOG0fLOcyEFLYLK79yGOAgWW7Po5Z9NEA1JpuHts2YhBSGCy/LLdyEGMYzD6tODMwwTVrje6p1NFAxMquTxw2seBSuOz/LNeSgGK4LA7tqNOAUUYLDq4qVQFAldqOPqtm4dBCeBy/DddjMHLITA7dWKNwcRXrPo6ahSEQ1LrOTms2AhBC2Jy+rMeSAGPYzG7daIOwgWW7fs6Z9OEwxHn93st2YhBS2F0fDYfy4FHozJ7NaONwkQXaTr4qVTEwlKp+HwtmkhBiuO0/HQdSsGLYnG7tqJPAcTZ7fq5aVOFAxMpd/uwHEhBS6F0PbNeSMFLYvK7NeMOQkTYrDz46JQEwxGpOLluWQhBy2H0PXLci4Hm4zG7dqQPQkSYbXy4qVTEglGpuPruGghBy2J0vXNfC0FLYnK7NePOgkUZ7Xu5adTEgpKp+Lwt2MhBiuGy/LSfC8FLo7A7NSLOgQUZLDf5q1TFAhLpOHxtnEhBS2FzfLNfC8HLobM6taPNwcUYrTq5aVSEwpJn+PruGshBTOGy/bReDAELYnJ6tSNOQQUYrLx4qFQFAwGoOPrtGMhBS+GyfHReSMHMo7F7tWHOwUVYLDs5Z9QEwlHnuDprWUhBjOOy/HUeSkFLInJ7NaONwkUYrLu46ROFAhGpOLuu2QhBy2GzfLQeSEHL4vL69WNOgQVX7Ds5aRTFQhGnuDssGYhBTOLy/LSeTMFL4nJ6taPNwcQXbDt46FQEwlGnuLurmQhBy2GyPHRdyQHL4nM69WNOwYUXbTu5aVSFQlFneC...'); // 簡化的成功音效
                audio.volume = 0.3;
                audio.play().catch(() => {}); // 忽略播放失敗
            } catch (e) {
                // 忽略音效播放錯誤
            }
        }

        // 播放錯誤音效
        function playErrorSound() {
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRvQAAABXQVZFZm10IBAAAAABAAEAESsAABErAAABAAgAZGF0YdAAAAA2k4y/m5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICAm5c2k4zAm5c2k4y/m5cAgICA');
                audio.volume = 0.2;
                audio.play().catch(() => {});
            } catch (e) {
                // 忽略音效播放錯誤
            }
        }
    });
    </script>

    <?php
    return ob_get_clean();
}


?>