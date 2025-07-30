<?php
/*
Plugin Name: QRCode User Manager
Description: پلاگین تولید QRCode برای کاربران با نام، نام خانوادگی و شماره موبایل.
Version: 1.0
Author: Abolfazl Samiei
*/

defined('ABSPATH') or die('No script kiddies please!');

// جدول‌های دیتابیس هنگام فعال‌سازی
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // جدول کاربران QR
    $users_table = $wpdb->prefix . 'qrcode_users';
    $sql1 = "CREATE TABLE IF NOT EXISTS $users_table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        phone VARCHAR(20),
        unique_id VARCHAR(100) UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    // جدول لاگ بازدیدها
    $logs_table = $wpdb->prefix . 'qrcode_view_logs';
    $sql2 = "CREATE TABLE IF NOT EXISTS $logs_table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uid VARCHAR(255),
        ip_address VARCHAR(100),
        location VARCHAR(255),
        user_agent TEXT,
        viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
});



// افزودن منوی مدیریت و زیرمنو
add_action('admin_menu', function () {
    // منوی اصلی
    add_menu_page(
        'مدیریت QRCodeها',
        'QRCodeها',
        'manage_options',
        'qrcode-users',
        'qrcode_admin_page',
        'dashicons-visibility', // آیکون دلخواه
        26 // موقعیت نمایش
    );

    // زیرمنو: تاریخچه بازدیدها
    add_submenu_page(
        'qrcode-users',             // slug والد
        'تاریخچه بازدیدها',         // عنوان صفحه
        'تاریخچه بازدیدها',         // عنوان منو
        'manage_options',           // دسترسی
        'qrcode-view-logs',         // slug زیرمنو
        'qrcode_view_logs_page'     // تابع نمایش صفحه
    );
});


// فایل مربوط به مدیریت ادمین
require_once plugin_dir_path(__FILE__) . 'admin.php';
require_once plugin_dir_path(__FILE__) . 'qrcode-view-logs.php';

function show_qrcode_info_shortcode() {
    if (!isset($_GET['uid'])) {
        return '<p>کد QR نامعتبر است.</p>';
    }

    session_start(); // استفاده از سشن برای ذخیره OTP

    global $wpdb;
    $table = $wpdb->prefix . 'qrcode_users';
    $uid = sanitize_text_field($_GET['uid']);

    $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE unique_id = %s", $uid));

    if (!$data) {
        return '<p>کد QR یافت نشد.</p>';
    }

    $output = '';

    // مرحله ارسال OTP
    if (isset($_POST['send_otp'])) {
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name = sanitize_text_field($_POST['last_name']);
        $phone = sanitize_text_field($_POST['phone']);

        $_SESSION['otp_data'] = [
            'uid' => $uid,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
        ];

        $otp = rand(100000, 999999);
        $_SESSION['otp_code'] = $otp;

        // ارسال کد از طریق sms.ir
        send_sms_ir_otp($phone, $otp);

        $output .= '<p>کد تأیید به شماره موبایل ارسال شد.</p>';
        $output .= '<form method="post">
            <label>کد تایید:</label><br>
            <input type="text" name="entered_otp" required value="'.$otp.'">
            <input type="submit" name="verify_otp" value="تأیید نهایی">
        </form>';

        return $output;
    }

    // مرحله بررسی OTP و ذخیره در دیتابیس
    if (isset($_POST['verify_otp']) && isset($_SESSION['otp_code'])) {
        $entered_otp = sanitize_text_field($_POST['entered_otp']);
        if ($entered_otp == $_SESSION['otp_code']) {
            $otp_data = $_SESSION['otp_data'];

            $wpdb->update($table, [
                'first_name' => $otp_data['first_name'],
                'last_name' => $otp_data['last_name'],
                'phone' => $otp_data['phone'],
            ], ['unique_id' => $otp_data['uid']]);

            // پاک کردن سشن
            unset($_SESSION['otp_code']);
            unset($_SESSION['otp_data']);

            $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE unique_id = %s", $uid));
            $output .= '<p>اطلاعات با موفقیت به‌روزرسانی شد.</p>';
        } else {
            $output .= '<p style="color:red;">کد تأیید نادرست است.</p>';
        }
    }

    // اگر اطلاعات کامل نیست یا در حالت ویرایش هستیم
    if (empty($data->first_name) || empty($data->phone) || empty($data->last_name) || isset($_GET['edit'])) {
        $output .= '<form method="post">
            <label>نام:</label><br>
            <input type="text" name="first_name" value="' . esc_attr($data->first_name) . '" required><br><br>
            <label>خانوادگی:</label><br>
            <input type="text" name="last_name" value="' . esc_attr($data->last_name) . '" required><br><br>
            <label>شماره موبایل:</label><br>
            <input type="text" name="phone" value="' . esc_attr($data->phone) . '" required><br><br>
            <input type="submit" name="send_otp" value="ارسال کد تأیید">
        </form>';
        return $output;
    }

    // حالت نمایش
    $output .= '<div class="qrcode-info">
        <h2>اطلاعات ثبت‌شده</h2>
        <p><strong>نام:</strong> ' . esc_html($data->first_name) . '</p>
        <p><strong>نام خانوادگی:</strong> ' . esc_html($data->last_name) . '</p>
        <p><strong>شماره موبایل:</strong> ' . esc_html($data->phone) . '</p>
        <p><strong>شناسه QR:</strong> ' . esc_html($data->unique_id) . '</p>
        <a href="?uid=' . urlencode($uid) . '&edit=true">درخواست ویرایش</a>
    </div>';


    //-----
    // لاگ‌گیری
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $location = get_location_by_ip($ip_address); // تابع پایین را هم بساز

    $wpdb->insert($wpdb->prefix . 'qrcode_view_logs', [
        'uid' => $uid,
        'ip_address' => $ip_address,
        'location' => $location,
        'user_agent' => $user_agent,
        'viewed_at' => current_time('mysql'),
    ]);

   // send_sms_ir_otp('09123450947', 'اطلاعات شما مشاهده شد');
    return $output;
}

function send_sms_ir_otp($mobile, $otp) {
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.sms.ir/v1/send/verify',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
        "mobile": "09124525319",
        "templateId": 533762,
        "parameters": [
          {
            "name": "VERIFICATIONCODE",
            "value": "'.$otp.'"
          }
        ]
      }',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: text/plain',
            'x-api-key: RnbjouOx12fZayeS6V5ERJyqQqAD7ouM1DY8qOlM5bYZMw5HvMGuCJ7mGYvkOLTd'
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);
   // echo $response;
}

// ✅‌ تابع ارسال پیامک با SMS.ir
function send_sms($mobile, $message) {
    $apiKey = 'RnbjouOx12fZayeS6V5ERJyqQqAD7ouM1DY8qOlM5bYZMw5HvMGuCJ7mGYvkOLTd'; // <-- این مقدار را با کلید اصلی SMS.ir جایگزین کنید
    $lineNumber = '300089930009';        // شماره ارسال پیامک شما در پنل

    $url = 'https://api.sms.ir/v1/send';

    $data = [
        "mobile" => '09124525319',
        "message" => $message,
        "lineNumber" => $lineNumber
    ];

    $args = [
        'body'        => json_encode($data),
        'headers'     => [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'X-API-KEY'     => $apiKey
        ],
        'timeout'     => 15,
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('خطا در ارسال پیامک: ' . $response->get_error_message());
    }
    echo "++++++++";
    print_r($response);
}

function get_location_by_ip($ip) {
    $response = wp_remote_get("http://ip-api.com/json/5.74.13.110?lang=fa");

    if (is_wp_error($response)) {
        return 'موقعیت نامشخص';
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body);

    if ($data && $data->status === 'success') {
        return "{$data->country} - {$data->regionName} - {$data->city} - {$data->lat}.{$data->lon}";
    }
    return 'موقعیت نامشخص';
}


add_shortcode('show_qrcode_info', 'show_qrcode_info_shortcode');
