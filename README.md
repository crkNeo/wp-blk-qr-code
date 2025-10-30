# BookingPress QR Code Extension

一個為 BookingPress 預訂系統添加 QR Code 生成和核銷功能的 WordPress 插件。

## 🚀 功能特色

### 📱 QR Code 生成
- **自動生成**：預訂完成後自動為每個預訂生成唯一的 QR Code
- **獨立存儲**：使用獨立的資料庫表存儲 QR Code 資料，不影響原有系統
- **多重備援**：支援多個 QR Code 生成 API，確保生成成功率
- **安全驗證碼**：每個 QR Code 包含唯一的驗證碼格式 `BP00000123ABCDEF`

### 🔍 QR Code 核銷
- **掃描核銷**：支援手機掃描 QR Code 進行核銷
- **手動輸入**：支援手動輸入驗證碼進行核銷
- **即時反饋**：核銷成功後立即顯示詳細資訊
- **防重複核銷**：已核銷的 QR Code 無法重複使用

### 📊 核銷管理
- **核銷紀錄**：顯示最近 10 筆核銷紀錄
- **詳細資訊**：包含客戶姓名、服務項目、預約日期等
- **即時更新**：核銷成功後自動更新紀錄列表

### 👤 客戶端功能
- **我的預訂**：在客戶的預訂頁面顯示 QR Code
- **行動友善**：支援手機瀏覽器顯示和掃描

## 📋 系統需求

- WordPress 5.0+
- PHP 7.4+
- BookingPress 插件
- MySQL 5.7+

## 🛠 安裝說明

1. **上傳插件文件**到 `/wp-content/plugins/bookingpress-qrcode-extension/` 目錄
2. **啟用插件**：在 WordPress 管理後台的「插件」頁面啟用此插件
3. **自動建表**：插件啟用時會自動創建必要的資料庫表
4. **設定權限**：確保核銷人員有適當的用戶權限

## 📖 使用方法

### 🎯 設置核銷頁面

1. 創建新頁面或編輯現有頁面
2. 添加短代碼：`[bookingpress_qr_verification]`
3. 發布頁面

### 📱 客戶使用流程

1. **完成預訂**：客戶在網站完成預訂
2. **獲取 QR Code**：系統自動生成 QR Code
3. **查看 QR Code**：在「我的預訂」頁面查看 QR Code
4. **現場出示**：到店時出示 QR Code 給服務人員

### 🔍 服務人員核銷流程

1. **開啟核銷頁面**：訪問包含 `[bookingpress_qr_verification]` 短代碼的頁面
2. **掃描 QR Code**：使用頁面上的掃描器掃描客戶的 QR Code
3. **或手動輸入**：也可以手動輸入驗證碼
4. **確認核銷**：系統顯示核銷成功訊息和客戶資訊
5. **查看紀錄**：在同一頁面查看最近的核銷紀錄

## 🗄 資料庫結構

### wp_bookingpress_qrcodes 表

```sql
CREATE TABLE wp_bookingpress_qrcodes (
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
);
```

### 欄位說明

- `booking_id`：關聯到 `wp_bookingpress_entries.bookingpress_entry_id`
- `verification_code`：唯一驗證碼，格式：`BP00000123ABCDEF`
- `qrcode_url`：QR Code 圖片的 URL
- `verified_at`：核銷時間（NULL 表示未核銷）
- `verified_by`：核銷人員的用戶 ID

## 🎨 短代碼

### [bookingpress_qr_verification]
創建 QR Code 核銷頁面

**功能包含：**
- QR Code 掃描器
- 手動驗證碼輸入
- 核銷結果顯示
- 最近核銷紀錄

**權限要求：**
- `edit_posts` 或 `manage_bookingpress_settings` 權限

## 🔧 技術細節

### QR Code 生成流程

1. **觸發時機**：預訂完成後自動觸發
2. **驗證碼生成**：`BP` + 8位預訂ID + 6位隨機碼
3. **圖片生成**：使用 Google Charts API 或備用 API
4. **存儲位置**：`/wp-content/uploads/bookingpress-qrcodes/`

### 核銷驗證流程

1. **接收驗證碼**：掃描或手動輸入
2. **資料庫查詢**：檢查驗證碼是否存在且有效
3. **狀態檢查**：確認未被核銷過
4. **更新記錄**：標記為已核銷並記錄時間
5. **返回結果**：顯示核銷成功訊息

### AJAX 端點

- `verify_booking_qrcode`：處理 QR Code 核銷
- `get_recent_verifications`：獲取最近核銷紀錄

## 📁 文件結構

```
bookingpress-qrcode-extension/
├── bookingpress-qrcode-extension.php    # 主插件文件
├── includes/
│   ├── class-qrcode-extension.php       # 核心功能類
│   └── shortcodes.php                   # 短代碼定義
├── assets/
│   ├── css/
│   │   └── qrcode-style.css            # 樣式文件
│   └── js/
│       ├── qrcode-script.js            # 客戶端腳本
│       ├── qrcode-verification.js      # 核銷頁面腳本
│       └── qrcode-debug.js             # 調試腳本
└── README.md                           # 說明文件
```

## 🔒 安全特性

- **Nonce 驗證**：所有 AJAX 請求都包含 nonce 驗證
- **權限檢查**：核銷功能需要適當的用戶權限
- **SQL 注入防護**：使用 WordPress 的 `$wpdb->prepare()` 方法
- **XSS 防護**：所有輸出都經過適當的轉義處理

## 🐛 故障排除

### QR Code 無法生成
1. 檢查網絡連接是否正常
2. 確認上傳目錄權限設置正確
3. 查看 WordPress 錯誤日誌

### 核銷功能無法使用
1. 確認用戶有適當權限
2. 檢查資料庫表是否正確創建
3. 驗證 AJAX 請求是否正常

### 掃描器無法啟動
1. 確認瀏覽器支援攝像頭
2. 檢查 HTTPS 連接（某些瀏覽器要求）
3. 允許網站訪問攝像頭權限

## 📝 更新日誌

### v1.0.0 (2024-01-XX)
- ✅ 初始版本發布
- ✅ QR Code 自動生成功能
- ✅ 掃描和手動核銷功能
- ✅ 核銷紀錄管理
- ✅ 客戶端 QR Code 顯示
- ✅ 獨立資料庫表設計
- ✅ 完整的安全驗證機制

## 🤝 支援

如果您遇到任何問題或需要技術支援，請：

1. 檢查本 README 的故障排除部分
2. 查看 WordPress 錯誤日誌
3. 聯繫開發團隊

## 📄 授權

本插件採用 GPL v2 或更新版本授權。

---

**注意**：本插件需要 BookingPress 插件才能正常運行。請確保已正確安裝和配置 BookingPress。