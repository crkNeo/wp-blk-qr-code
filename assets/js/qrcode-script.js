jQuery(document).ready(function($) {

    function addQRCodeToBooking() {
        console.log('開始檢查展開的預訂');

        // 檢查 PHP 傳遞的資料是否存在
        if (!window.bookingpress_qrcode_data || !window.bookingpress_qrcode_data.bookings) {
            console.log('QR Code 資料未載入');
            return;
        }

        console.log('可用的 QR Code 資料:', window.bookingpress_qrcode_data.bookings);

        // 直接尋找已展開的預訂卡片
        $('.el-table__expanded-cell .bpa-sd__appointment-id').each(function() {
            const $idElement = $(this);
            const $bookingCard = $idElement.closest('.bpa-front-ma-view-appointment-card');

            // 檢查是否已添加 QR Code
            if ($bookingCard.find('.booking-qrcode-section').length > 0) {
                return; // 已經添加過，跳過
            }

            const bookingIdText = $idElement.text();
            const bookingId = bookingIdText.replace(/[^\d]/g, '');

            console.log('找到預訂 ID:', bookingId);

            // 檢查是否有對應的 QR Code 資料
            if (bookingId && window.bookingpress_qrcode_data.bookings[bookingId]) {
                const bookingData = window.bookingpress_qrcode_data.bookings[bookingId];

                // 確保有必要的資料
                if (!bookingData.qrcode_url || !bookingData.verification_code) {
                    console.log(`預訂 ${bookingId} 缺少必要的 QR Code 資料`);
                    return;
                }

                const qrCodeHtml = `
                    <div class="booking-qrcode-section" style="margin-top: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 8px; background: #f8f9fa;">
                        <div class="bpa-ma-vac-sec-title" style="margin-bottom: 15px; font-weight: 600;">現場核銷 QR Code:</div>
                        <div class="booking-qrcode" style="text-align: center;">
                            <img src="${bookingData.qrcode_url}" alt="QR Code" class="qrcode-image" style="max-width: 150px; margin: 10px auto; display: block; cursor: pointer; border: 1px solid #ddd; border-radius: 4px;" />
                            <div class="bpa-vac-pd__item" style="margin: 10px 0;">
                                <strong>驗證碼: ${bookingData.verification_code}</strong>
                            </div>
                            <div style="margin-top: 15px;">
                                <button type="button" class="el-button bpa-front-btn bpa-front-btn__small btn-download-qrcode" data-url="${bookingData.qrcode_url}" style="margin-right: 10px;">
                                    下載 QR Code
                                </button>
                                <button type="button" class="el-button bpa-front-btn bpa-front-btn__small btn-enlarge-qrcode" data-url="${bookingData.qrcode_url}">
                                    放大顯示
                                </button>
                            </div>
                        </div>
                    </div>
                `;

                // 添加到付款詳情後面
                const $paymentDetails = $bookingCard.find('.bpa-ma-vac--payment-details');
                if ($paymentDetails.length > 0) {
                    $paymentDetails.after(qrCodeHtml);
                } else {
                    $bookingCard.append(qrCodeHtml);
                }

                console.log(`成功為預訂 ${bookingId} 添加 QR Code`);
            } else {
                console.log(`預訂 ${bookingId} 沒有對應的 QR Code 資料`);
                console.log('可用的預訂 ID:', Object.keys(window.bookingpress_qrcode_data.bookings));
            }
        });
    }

    // 監聽展開按鈕點擊
    $(document).on('click', '.el-table__expand-icon', function() {
        console.log('展開按鈕被點擊');
        setTimeout(addQRCodeToBooking, 500);
    });

    // 下載 QR Code 功能
    $(document).on('click', '.btn-download-qrcode', function(e) {
        e.preventDefault();
        const imageUrl = $(this).data('url');
        if (imageUrl) {
            const link = document.createElement('a');
            link.href = imageUrl;
            link.download = 'booking-qrcode.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            console.log('QR Code 下載完成');
        }
    });

    // 放大顯示 QR Code
    $(document).on('click', '.btn-enlarge-qrcode, .qrcode-image', function(e) {
        e.preventDefault();
        const imageUrl = $(this).data('url') || $(this).attr('src');
        if (imageUrl) {
            const modal = `
                <div class="qrcode-modal-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div class="qrcode-modal" style="background: white; padding: 30px; border-radius: 10px; text-align: center; max-width: 400px;">
                        <div style="margin-bottom: 20px;">
                            <h3 style="margin: 0 0 10px 0;">請出示此 QR Code 給服務人員</h3>
                        </div>
                        <img src="${imageUrl}" alt="QR Code" style="max-width: 250px; margin: 20px 0;" />
                        <div style="margin-top: 20px;">
                            <button class="qrcode-modal-close el-button bpa-front-btn" style="padding: 10px 20px;">關閉</button>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(modal);
            $('.qrcode-modal-overlay').fadeIn(200);
        }
    });

    // 關閉 QR Code 彈窗
    $(document).on('click', '.qrcode-modal-close, .qrcode-modal-overlay', function(e) {
        if (e.target === this || $(e.target).hasClass('qrcode-modal-close')) {
            $('.qrcode-modal-overlay').fadeOut(200, function() {
                $(this).remove();
            });
        }
    });

    // 核銷功能（保持不變）
    function initVerificationScanner() {
        if ($('.qrcode-scanner-container').length === 0) {
            return;
        }

        $(document).on('click', '.btn-manual-verify', function() {
            const verificationCode = $('#manual-verification-code').val().trim();
            if (!verificationCode) {
                alert('請輸入驗證碼');
                return;
            }
            verifyBooking(verificationCode);
        });
    }

    function verifyBooking(verificationCode) {
        if (!window.bookingpress_qrcode_data || !window.bookingpress_qrcode_data.nonce) {
            alert('系統錯誤：缺少必要的驗證數據');
            return;
        }

        const data = {
            action: 'verify_booking_qrcode',
            verification_code: verificationCode,
            nonce: window.bookingpress_qrcode_data.nonce
        };

        $.post(window.bookingpress_qrcode_data.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    alert(`核銷成功！\n客戶: ${response.data.customer_name}\n服務: ${response.data.service_name}\n日期: ${response.data.appointment_date}`);
                    $('#manual-verification-code').val('');
                } else {
                    alert('核銷失敗: ' + response.data);
                }
            })
            .fail(function() {
                alert('網絡錯誤，請重試');
            });
    }

    // 初始化
    initVerificationScanner();

    // 頁面載入時檢查
    setTimeout(addQRCodeToBooking, 1000);

    // 定期檢查（較低頻率）
    setInterval(addQRCodeToBooking, 5000);

    console.log('QR Code 腳本初始化完成');
});