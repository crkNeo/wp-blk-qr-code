/**
 * BookingPress QR Code 核銷頁面腳本
 * 處理核銷頁面的互動功能，包含掃描和手動輸入
 */

jQuery(document).ready(function($) {
    
    let html5QrcodeScanner = null;

    // 初始化 QR Code 掃描器
    function initQRScanner() {
        if (typeof Html5QrcodeScanner === 'undefined') {
            console.log('Html5QrcodeScanner 庫載入中...');
            setTimeout(initQRScanner, 1000);
            return;
        }

        html5QrcodeScanner = new Html5QrcodeScanner(
            "qr-reader",
            { 
                fps: 10, 
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                showTorchButtonIfSupported: true,
                showZoomSliderIfSupported: true,
                defaultZoomValueIfSupported: 2
            },
            false
        );
        
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        console.log('QR Code 掃描器已初始化');
    }

    // 掃描成功回調
    function onScanSuccess(decodedText, decodedResult) {
        console.log('QR Code 掃描成功:', decodedText);
        
        // 停止掃描
        if (html5QrcodeScanner) {
            html5QrcodeScanner.clear();
        }
        
        // 處理掃描結果
        handleScanResult(decodedText);
    }

    // 掃描失敗回調
    function onScanFailure(error) {
        // 不需要處理每次掃描失敗，這很正常
    }

    // 處理掃描結果
    function handleScanResult(scannedData) {
        console.log('處理掃描數據:', scannedData);
        
        // 嘗試解析 JSON 格式的 QR Code
        try {
            const jsonData = JSON.parse(scannedData);
            if (jsonData.verification_code) {
                scannedData = jsonData.verification_code;
            }
        } catch (e) {
            // 如果不是 JSON，直接使用原始數據
        }

        // 檢查是否是有效的驗證碼格式
        if (scannedData && scannedData.match(/^BP\d{8}[A-F0-9]{6}$/)) {
            // 自動填入驗證碼
            $('#manual-verification-code').val(scannedData);
            
            // 顯示成功訊息
            showMessage('掃描成功！正在核銷...', 'success');
            
            // 自動執行核銷
            setTimeout(() => {
                verifyBooking(scannedData);
            }, 500);
        } else {
            showMessage('掃描到的不是有效的預訂 QR Code: ' + scannedData, 'error');
            
            // 重新啟動掃描
            setTimeout(() => {
                initQRScanner();
            }, 2000);
        }
    }

    // 初始化掃描器
    initQRScanner();
    
    // 手動輸入驗證碼核銷
    $(document).on('click', '.btn-manual-verify', function() {
        const verificationCode = $('#manual-verification-code').val().trim();
        
        if (!verificationCode) {
            showMessage('請輸入驗證碼', 'error');
            return;
        }
        
        verifyBooking(verificationCode);
    });
    
    // Enter 鍵觸發核銷
    $(document).on('keypress', '#manual-verification-code', function(e) {
        if (e.which === 13) {
            $('.btn-manual-verify').click();
        }
    });

    // 移除舊的掃描相關函數，改用 Html5QrcodeScanner
    
    // 核銷預訂
    function verifyBooking(verificationCode) {
        const $button = $('.btn-manual-verify');
        const originalText = $button.text();
        
        // 顯示載入狀態
        $button.text('核銷中...').prop('disabled', true);
        
        const data = {
            action: 'verify_booking_qrcode',
            verification_code: verificationCode,
            nonce: bookingpress_qrcode.nonce
        };
        
        $.post(bookingpress_qrcode.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showVerificationSuccess(response.data);
                    $('#manual-verification-code').val('');
                    // 重新載入核銷紀錄
                    loadRecentVerifications();
                } else {
                    showMessage('核銷失敗: ' + response.data, 'error');
                }
            })
            .fail(function() {
                showMessage('網絡錯誤，請重試', 'error');
            })
            .always(function() {
                $button.text(originalText).prop('disabled', false);
            });
    }
    
    // 顯示核銷成功信息
    function showVerificationSuccess(data) {
        const successHtml = `
            <div class="verification-success">
                <h3>✅ 核銷成功！</h3>
                <div class="booking-details">
                    <p><strong>預訂編號:</strong> ${data.booking_id || '未知'}</p>
                    <p><strong>客戶姓名:</strong> ${data.customer_name || '未知客戶'}</p>
                    <p><strong>服務項目:</strong> ${data.service_name || '未知服務'}</p>
                    <p><strong>預約日期:</strong> ${data.appointment_date || '未知日期'}</p>
                    <p><strong>核銷時間:</strong> ${data.verification_time || new Date().toLocaleString()}</p>
                </div>
            </div>
        `;
        
        $('#verification-result').html(successHtml).removeClass('hidden').addClass('success');
        
        // 播放成功音效
        playSuccessSound();
        
        // 5秒後自動清除結果
        setTimeout(function() {
            $('#verification-result').fadeOut(function() {
                $(this).html('').removeClass('success').show();
            });
        }, 5000);
    }
    
    // 顯示消息
    function showMessage(message, type = 'info') {
        const messageHtml = `
            <div class="verification-message ${type}">
                ${message}
            </div>
        `;
        
        $('#verification-result').html(messageHtml);
        
        setTimeout(function() {
            $('#verification-result').fadeOut(function() {
                $(this).html('').show();
            });
        }, 3000);
    }

    // 頁面卸載時停止掃描
    $(window).on('beforeunload', function() {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.clear();
        }
    });

    // 載入最近核銷紀錄
    function loadRecentVerifications() {
        const data = {
            action: 'get_recent_verifications',
            nonce: bookingpress_qrcode.nonce
        };
        
        $.post(bookingpress_qrcode.ajax_url, data)
            .done(function(response) {
                if (response.success && response.data.length > 0) {
                    let recordsHtml = '';
                    response.data.forEach(function(record) {
                        const verifiedDate = new Date(record.verified_at);
                        recordsHtml += `
                            <div class="verification-item">
                                <div class="verification-info">
                                    <strong>預訂 #${record.booking_id}</strong> - ${record.customer_name}
                                    <br>
                                    <small>${record.service_name} | ${record.appointment_date}</small>
                                    <br>
                                    <small class="verification-time">核銷時間: ${verifiedDate.toLocaleString()}</small>
                                </div>
                                <div class="verification-code-display">
                                    <small>${record.verification_code}</small>
                                </div>
                            </div>
                        `;
                    });
                    $('#recent-verifications-list').html(recordsHtml);
                } else {
                    $('#recent-verifications-list').html('<p style="color: #6c757d; text-align: center; padding: 20px;">尚無核銷記錄</p>');
                }
            })
            .fail(function() {
                $('#recent-verifications-list').html('<p style="color: #dc3545; text-align: center; padding: 20px;">載入核銷記錄失敗</p>');
            });
    }

    // 播放成功音效
    function playSuccessSound() {
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N+QQAoUXrTp66hVFApGn+DyvmwhBSuGz/DZdycEL4nO7tqMOQcTYbbr56ZSEgxGoOHuuW8gBjOG0fLOcyEFLYLK79yGOAgWW7Po5Z9NEA1JpuHts2YhBSGCy/LLdyEGMYzD6tODMwwTVrje6p1NFAxMquTxw2seBSuOz/LNeSgGK4LA7tqNOAUUYLDq4qVQFAldqOPqtm4dBCeBy/DddjMHLITA7dWKNwcRXrPo6ahSEQ1LrOTms2AhBC2Jy+rMeSAGPYzG7daIOwgWW7fs6Z9OEwxHn93st');
            audio.volume = 0.3;
            audio.play().catch(() => {}); // 忽略播放失敗
        } catch (e) {
            // 忽略音效播放錯誤
        }
    }

    // 頁面載入時獲取最近的核銷記錄
    loadRecentVerifications();

    console.log('BookingPress QR Code 核銷頁面已載入，使用 Html5QrcodeScanner');
});