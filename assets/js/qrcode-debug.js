/**
 * BookingPress QR Code 調試腳本
 * 監聽 AJAX 請求並顯示調試信息
 */

jQuery(document).ready(function($) {
    
    // 監聽所有 AJAX 請求
    $(document).ajaxComplete(function(event, xhr, settings) {
        
        // 檢查是否是 BookingPress 預訂請求
        if (settings.data && settings.data.indexOf('bookingpress_book_appointment_booking') !== -1) {
            console.log('[BookingPress QR Code] 檢測到預訂 AJAX 請求');
            console.log('請求數據:', settings.data);
            
            // 檢查回應
            try {
                const response = JSON.parse(xhr.responseText);
                console.log('[BookingPress QR Code] AJAX 回應:', response);
                
                // 檢查 QR Code 調試信息
                if (response.qr_debug) {
                    console.group('[BookingPress QR Code] 調試信息');
                    console.log('Hook 觸發:', response.qr_debug.hook_triggered);
                    console.log('時間戳:', response.qr_debug.timestamp);
                    
                    if (response.qr_debug.messages && response.qr_debug.messages.length > 0) {
                        console.log('調試訊息:');
                        response.qr_debug.messages.forEach(function(msg) {
                            console.log('  -', msg);
                        });
                    }
                    console.groupEnd();
                }
                
                if (response.variant && response.variant === 'success') {
                    console.log('[BookingPress QR Code] 預訂成功，QR Code 生成中...');
                    
                    // 延遲檢查 QR Code 是否生成
                    setTimeout(function() {
                        console.log('[BookingPress QR Code] 檢查 QR Code 生成狀態...');
                        
                        // 檢查頁面是否有新的 QR Code
                        const qrCodes = $('.booking-qrcode-section');
                        if (qrCodes.length > 0) {
                            console.log('[BookingPress QR Code] ✅ 找到 QR Code 在頁面中');
                        } else {
                            console.log('[BookingPress QR Code] ⚠️ 頁面中尚未找到 QR Code');
                        }
                    }, 5000);
                }
            } catch (e) {
                console.log('[BookingPress QR Code] 無法解析 AJAX 回應:', e);
            }
        }
    });
    
    // 監聽預訂表單提交
    $(document).on('submit', '.bpa-front-form-wrapper form', function() {
        console.log('[BookingPress QR Code] 預訂表單提交');
    });
    
    // 顯示當前頁面的 QR Code 狀態
    if ($('.booking-qrcode-section').length > 0) {
        console.log('[BookingPress QR Code] 頁面中找到 ' + $('.booking-qrcode-section').length + ' 個 QR Code');
    }
    
    // 定期檢查新的 QR Code
    setInterval(function() {
        const qrCodes = $('.booking-qrcode-section');
        if (qrCodes.length > 0) {
            qrCodes.each(function(index) {
                const $qr = $(this);
                const verificationCode = $qr.find('.verification-code').text();
                if (verificationCode && !$qr.data('logged')) {
                    console.log('[BookingPress QR Code] 發現 QR Code:', verificationCode);
                    $qr.data('logged', true);
                }
            });
        }
    }, 5000);
    
    console.log('[BookingPress QR Code] 調試腳本已載入');
});