/**
 * BookingPress QR Code 核銷頁面腳本 - 使用 Html5-QRCode 庫
 * 穩定且手機支援好
 */

jQuery(document).ready(function($) {

    let html5QrcodeScanner = null;
    let isVerifying = false;

    // 初始化 QR Code 掃描器
    function initQRScanner() {
        if (typeof Html5QrcodeScanner === 'undefined') {
            console.log('Html5QrcodeScanner 庫載入中...');
            setTimeout(initQRScanner, 500);
            return;
        }

        try {
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader",
                {
                    fps: 30,  // 提高 FPS 以獲得更快的掃描速度
                    qrbox: function(viewfinderWidth, viewfinderHeight) {
                        // 響應式 qrbox，根據螢幕大小調整
                        let minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                        let qrboxSize = Math.floor(minEdge * 0.7);
                        return { width: qrboxSize, height: qrboxSize };
                    },
                    aspectRatio: 1.0,
                    showTorchButtonIfSupported: true,
                    showZoomSliderIfSupported: true,
                    defaultZoomValueIfSupported: 2,
                    rememberLastUsedCamera: true,
                    // 優先使用後置攝像頭
                    videoConstraints: {
                        facingMode: { ideal: "environment" }
                    }
                },
                false
            );

            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
            console.log('Html5-QRCode 掃描器已初始化');
        } catch (error) {
            console.error('初始化掃描器失敗:', error);
            showError('初始化失敗，請重新載入頁面');
        }
    }

    // 掃描成功回調
    function onScanSuccess(decodedText, decodedResult) {
        if (isVerifying) return; // 防止重複驗證

        console.log('QR Code 掃描成功:', decodedText);

        // 播放掃描成功嗶聲
        playBeepSound();

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
        let verificationCode = scannedData;
        try {
            const jsonData = JSON.parse(scannedData);
            if (jsonData.verification_code) {
                verificationCode = jsonData.verification_code;
            }
        } catch (e) {
            // 如果不是 JSON，直接使用原始數據
        }

        // 檢查是否是有效的驗證碼格式
        if (verificationCode && verificationCode.match(/^BP\d{8}[A-F0-9]{6}$/)) {
            // 暫停掃描
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear().catch(err => console.log('清除掃描器錯誤:', err));
            }

            // 自動填入驗證碼
            $('#manual-verification-code').val(verificationCode);

            // 自動執行核銷
            setTimeout(() => {
                verifyBooking(verificationCode);
            }, 300);
        } else {
            playErrorSound();
            showError('掃描到的不是有效的預訂 QR Code');
        }
    }

    // 核銷預訂
    function verifyBooking(verificationCode) {
        if (isVerifying) return;

        isVerifying = true;
        const $button = $('.btn-manual-verify');
        const originalText = $button.text();

        $button.prop('disabled', true).text('核銷中...');

        const data = {
            action: 'verify_booking_qrcode',
            verification_code: verificationCode,
            nonce: bookingpress_qrcode.nonce
        };

        $.post(bookingpress_qrcode.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showSuccessNotification(response.data);
                    $('#manual-verification-code').val('');

                    // 載入最新核銷記錄
                    loadRecentVerifications();

                    // 播放成功音效
                    playSuccessSound();
                } else {
                    showError(response.data || '核銷失敗');
                    playErrorSound();

                    // 3秒後重新啟動掃描
                    setTimeout(() => {
                        if (html5QrcodeScanner && html5QrcodeScanner.getState() === Html5QrcodeScannerState.NOT_STARTED) {
                            initQRScanner();
                        }
                    }, 3000);
                }
            })
            .fail(function() {
                showError('網絡錯誤，請檢查網絡連接後重試');
                playErrorSound();
            })
            .always(function() {
                isVerifying = false;
                $button.prop('disabled', false).text(originalText);
            });
    }

    // 顯示成功通知（彈窗）
    function showSuccessNotification(bookingData) {
        const notificationHtml = `
            <div class="notification-overlay">
                <div class="notification-modal">
                    <div class="notification-icon">✅</div>
                    <div class="notification-title">核銷成功！</div>
                    <div class="notification-details">
                        <p><strong>預訂編號:</strong> ${bookingData.booking_id || '未知'}</p>
                        <p><strong>客戶姓名:</strong> ${bookingData.customer_name || '未知客戶'}</p>
                        <p><strong>服務項目:</strong> ${bookingData.service_name || '未知服務'}</p>
                        <p><strong>預約日期:</strong> ${bookingData.appointment_date || '未知日期'}</p>
                        <p><strong>核銷時間:</strong> ${new Date().toLocaleString('zh-TW')}</p>
                    </div>
                    <button class="notification-close-btn">確定</button>
                </div>
            </div>
        `;

        $('body').append(notificationHtml);

        // 關閉通知
        $('.notification-close-btn, .notification-overlay').on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('notification-close-btn')) {
                $('.notification-overlay').fadeOut(300, function() {
                    $(this).remove();

                    // 關閉通知後自動重新啟動掃描
                    setTimeout(() => {
                        initQRScanner();
                    }, 500);
                });
            }
        });

        // 5秒後自動關閉
        setTimeout(() => {
            if ($('.notification-overlay').length > 0) {
                $('.notification-close-btn').click();
            }
        }, 5000);
    }

    // 顯示錯誤訊息
    function showError(message) {
        const $result = $('#verification-result');
        $result.removeClass('success info')
               .addClass('error')
               .html(`<strong>錯誤:</strong> ${message}`)
               .fadeIn();

        setTimeout(() => {
            $result.fadeOut();
        }, 3000);
    }

    // 載入最近核銷記錄
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
                                    <small class="verification-time">核銷時間: ${verifiedDate.toLocaleString('zh-TW')}</small>
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

    // 播放掃描成功嗶聲
    function playBeepSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = 800;
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        } catch (e) {
            console.log('無法播放音效');
        }
    }

    // 播放成功音效
    function playSuccessSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();

            // 播放兩個音調
            [600, 800].forEach((freq, index) => {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.frequency.value = freq;
                oscillator.type = 'sine';

                const startTime = audioContext.currentTime + (index * 0.15);
                gainNode.gain.setValueAtTime(0.3, startTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, startTime + 0.2);

                oscillator.start(startTime);
                oscillator.stop(startTime + 0.2);
            });
        } catch (e) {
            console.log('無法播放音效');
        }
    }

    // 播放錯誤音效
    function playErrorSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = 200;
            oscillator.type = 'sawtooth';

            gainNode.gain.setValueAtTime(0.2, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.3);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.3);
        } catch (e) {
            console.log('無法播放音效');
        }
    }

    // 手動輸入驗證碼核銷
    $(document).on('click', '.btn-manual-verify', function() {
        const verificationCode = $('#manual-verification-code').val().trim();

        if (!verificationCode) {
            showError('請輸入驗證碼');
            return;
        }

        if (verificationCode.length < 8) {
            showError('驗證碼格式不正確');
            return;
        }

        verifyBooking(verificationCode);
    });

    // Enter 鍵觸發核銷
    $(document).on('keypress', '#manual-verification-code', function(e) {
        if (e.which === 13 && !isVerifying) {
            $('.btn-manual-verify').click();
        }
    });

    // 輸入時自動轉換為大寫
    $(document).on('input', '#manual-verification-code', function() {
        this.value = this.value.toUpperCase();
    });

    // 頁面卸載時停止掃描
    $(window).on('beforeunload', function() {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.clear().catch(err => console.log('清除掃描器錯誤:', err));
        }
    });

    // 初始化
    initQRScanner();
    loadRecentVerifications();

    console.log('BookingPress QR Code 核銷系統已載入 (Html5-QRCode)');
});
