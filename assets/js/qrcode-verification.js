/**
 * BookingPress QR Code 核銷頁面腳本 - 使用 ZXing 庫
 * 更快的掃描速度和更好的性能
 */

jQuery(document).ready(function($) {

    let codeReader = null;
    let selectedDeviceId = null;
    let isScanning = false;
    let isVerifying = false;

    // 初始化掃描器
    function initScanner() {
        // 等待 ZXing 庫載入
        if (typeof ZXing === 'undefined') {
            console.log('ZXing 庫載入中...');
            setTimeout(initScanner, 500);
            return;
        }

        try {
            codeReader = new ZXing.BrowserMultiFormatReader();
            console.log('ZXing 掃描器已初始化');

            // 獲取可用的攝像頭
            loadCameras();
        } catch (error) {
            console.error('初始化掃描器失敗:', error);
            updateStatus('初始化失敗，請重新載入頁面', 'error');
        }
    }

    // 載入可用的攝像頭
    async function loadCameras() {
        try {
            const videoInputDevices = await codeReader.listVideoInputDevices();

            if (videoInputDevices.length === 0) {
                updateStatus('未找到攝像頭', 'error');
                return;
            }

            const $cameraSelect = $('#camera-select');
            $cameraSelect.empty();

            // 優先選擇後置攝像頭
            let backCameraFound = false;
            videoInputDevices.forEach((device, index) => {
                const option = $('<option></option>')
                    .val(device.deviceId)
                    .text(device.label || `攝像頭 ${index + 1}`);
                $cameraSelect.append(option);

                // 檢測後置攝像頭（通常包含 "back" 或 "rear"）
                if (!backCameraFound &&
                    (device.label.toLowerCase().includes('back') ||
                     device.label.toLowerCase().includes('rear') ||
                     device.label.toLowerCase().includes('後'))) {
                    selectedDeviceId = device.deviceId;
                    backCameraFound = true;
                }
            });

            // 如果沒找到後置攝像頭，使用第一個
            if (!selectedDeviceId && videoInputDevices.length > 0) {
                selectedDeviceId = videoInputDevices[0].deviceId;
            }

            // 設置選中的攝像頭
            $cameraSelect.val(selectedDeviceId);

            // 如果有多個攝像頭，顯示選擇器
            if (videoInputDevices.length > 1) {
                $cameraSelect.show();
            }

            updateStatus('準備就緒，點擊「開始掃描」', 'ready');
            console.log(`找到 ${videoInputDevices.length} 個攝像頭`);

        } catch (error) {
            console.error('載入攝像頭失敗:', error);
            updateStatus('無法訪問攝像頭，請檢查權限', 'error');
        }
    }

    // 開始掃描
    async function startScanning() {
        if (isScanning || !codeReader) return;

        const deviceId = $('#camera-select').val() || selectedDeviceId;

        if (!deviceId) {
            updateStatus('請選擇攝像頭', 'error');
            return;
        }

        try {
            isScanning = true;
            $('#start-scanner').hide();
            $('#stop-scanner').show();
            updateStatus('掃描中...請將 QR Code 對準框內', 'scanning');

            // 開始連續掃描
            await codeReader.decodeFromVideoDevice(
                deviceId,
                'qr-video',
                (result, error) => {
                    if (result) {
                        handleScanSuccess(result.text);
                    }
                    // 忽略掃描錯誤，繼續掃描
                }
            );

        } catch (error) {
            console.error('啟動掃描失敗:', error);
            updateStatus('啟動掃描失敗: ' + error.message, 'error');
            stopScanning();
        }
    }

    // 停止掃描
    function stopScanning() {
        if (codeReader) {
            codeReader.reset();
        }
        isScanning = false;
        $('#start-scanner').show();
        $('#stop-scanner').hide();
        updateStatus('已停止掃描', 'ready');
    }

    // 處理掃描成功
    function handleScanSuccess(scannedData) {
        if (isVerifying) return; // 防止重複驗證

        console.log('掃描到數據:', scannedData);

        // 暫停掃描
        stopScanning();

        // 嘗試解析 JSON 格式的 QR Code
        let verificationCode = scannedData;
        try {
            const jsonData = JSON.parse(scannedData);
            if (jsonData.verification_code) {
                verificationCode = jsonData.verification_code;
            }
        } catch (e) {
            // 如果不是 JSON，使用原始數據
        }

        // 驗證碼格式檢查
        if (verificationCode && verificationCode.match(/^BP\d{8}[A-F0-9]{6}$/)) {
            $('#manual-verification-code').val(verificationCode);
            updateStatus('掃描成功！正在核銷...', 'success');

            // 播放掃描成功音效
            playBeepSound();

            // 自動執行核銷
            setTimeout(() => {
                verifyBooking(verificationCode);
            }, 300);
        } else {
            updateStatus('無效的 QR Code: ' + verificationCode, 'error');
            playErrorSound();

            // 2秒後重新開始掃描
            setTimeout(() => {
                startScanning();
            }, 2000);
        }
    }

    // 更新狀態顯示
    function updateStatus(message, type) {
        const $status = $('#scanner-status');
        $status.removeClass('scanning success error ready')
               .addClass(type)
               .text(message);
    }

    // 核銷預訂
    function verifyBooking(verificationCode) {
        if (isVerifying) return;

        isVerifying = true;
        const $button = $('.btn-manual-verify');
        const originalText = $button.text();

        $button.prop('disabled', true).text('核銷中...');
        updateStatus('正在驗證中...', 'scanning');

        const data = {
            action: 'verify_booking_qrcode',
            verification_code: verificationCode,
            nonce: bookingpress_qrcode.nonce
        };

        $.post(bookingpress_qrcode.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    updateStatus('核銷成功！', 'success');
                    showSuccessNotification(response.data);
                    $('#manual-verification-code').val('');

                    // 載入最新核銷記錄
                    loadRecentVerifications();

                    // 播放成功音效
                    playSuccessSound();
                } else {
                    updateStatus('核銷失敗: ' + response.data, 'error');
                    showErrorMessage(response.data || '核銷失敗');
                    playErrorSound();

                    // 3秒後重新開始掃描
                    setTimeout(() => {
                        if (!isScanning) {
                            startScanning();
                        }
                    }, 3000);
                }
            })
            .fail(function(xhr, status, error) {
                updateStatus('網絡錯誤，請重試', 'error');
                showErrorMessage('網絡錯誤，請檢查網絡連接後重試');
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

                    // 關閉通知後自動重新開始掃描
                    if (!isScanning) {
                        setTimeout(() => {
                            startScanning();
                        }, 500);
                    }
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
    function showErrorMessage(message) {
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

    // 事件監聽器
    $('#start-scanner').on('click', function() {
        startScanning();
    });

    $('#stop-scanner').on('click', function() {
        stopScanning();
    });

    $('#camera-select').on('change', function() {
        selectedDeviceId = $(this).val();
        if (isScanning) {
            stopScanning();
            setTimeout(() => startScanning(), 300);
        }
    });

    // 手動輸入驗證碼核銷
    $('.btn-manual-verify').on('click', function() {
        const verificationCode = $('#manual-verification-code').val().trim();

        if (!verificationCode) {
            updateStatus('請輸入驗證碼', 'error');
            return;
        }

        if (verificationCode.length < 8) {
            updateStatus('驗證碼格式不正確', 'error');
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

    // 頁面卸載時停止掃描
    $(window).on('beforeunload', function() {
        stopScanning();
    });

    // 頁面失去焦點時暫停掃描（節省資源）
    $(document).on('visibilitychange', function() {
        if (document.hidden && isScanning) {
            stopScanning();
            updateStatus('頁面失去焦點，已暫停掃描', 'ready');
        }
    });

    // 初始化
    initScanner();
    loadRecentVerifications();

    console.log('BookingPress QR Code 核銷系統已載入 (ZXing)');
});
