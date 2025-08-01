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
        show_first_name TINYINT(1) DEFAULT 1,
        show_last_name TINYINT(1) DEFAULT 1,
        show_phone TINYINT(1) DEFAULT 1,
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
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
    wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', [], null, true);
});

function show_qrcode_info_shortcode() {
    if (!isset($_GET['uid'])) {
        return '<div class="alert alert-danger">کد QR نامعتبر است.</div>';
    }

    session_start();
    global $wpdb;
    $table = $wpdb->prefix . 'qrcode_users';
    $uid = sanitize_text_field($_GET['uid']);

    $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE unique_id = %s", $uid));
    if (!$data) {
        return '<div class="alert alert-danger">کد QR یافت نشد.</div>';
    }

    $output = '<div class="container my-4">';

    // مرحله ارسال OTP
    if (isset($_POST['send_otp'])) {
        $first_name = sanitize_text_field($_POST['first_name']);
        $last_name  = sanitize_text_field($_POST['last_name']);
        $phone      = sanitize_text_field($_POST['phone']);
        $show_first_name      = isset($_POST['show_first_name']) ? sanitize_text_field($_POST['show_first_name']) : null;
        $show_last_name      = isset($_POST['show_last_name']) ? sanitize_text_field($_POST['show_last_name']) : null;
        $show_phone      = isset($_POST['show_phone']) ? sanitize_text_field($_POST['show_phone']) : null;

        $_SESSION['otp_data'] = [
            'uid'        => $uid,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'phone'      => $phone,
            'show_first_name'      => $show_first_name,
            'show_last_name'      => $show_last_name,
            'show_phone'      => $show_phone,
        ];

        $otp = rand(100000, 999999);
        $_SESSION['otp_code'] = $otp;

        send_sms_ir_otp($phone, $otp);

        $output .= '<div class="alert alert-success">کد تأیید به شماره موبایل ارسال شد.</div>';
        $output .= '
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label for="entered_otp" class="form-label">کد تایید:</label>
                <input type="text" name="entered_otp" id="entered_otp" required class="form-control" />
            </div>
            <div class="col-12">
                <button type="submit" name="verify_otp" class="btn btn-primary">تأیید نهایی</button>
            </div>
        </form>';
        $output .= '</div>';
        return $output;
    }

    // مرحله بررسی OTP
    if (isset($_POST['verify_otp']) && isset($_SESSION['otp_code'])) {
        $entered_otp = sanitize_text_field($_POST['entered_otp']);
        if ($entered_otp == $_SESSION['otp_code']) {
            $otp_data = $_SESSION['otp_data'];

            $wpdb->update($table, [
                'first_name' => $otp_data['first_name'],
                'last_name'  => $otp_data['last_name'],
                'phone'      => $otp_data['phone'],
                'show_first_name' => $otp_data['show_first_name'] ? 1 : 0,
                'show_last_name'  => $otp_data['show_last_name'] ? 1 : 0,
                'show_phone'      => $otp_data['show_phone'] ? 1 : 0,
            ], ['unique_id' => $otp_data['uid']]);

            unset($_SESSION['otp_code'], $_SESSION['otp_data']);
            $data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE unique_id = %s", $uid));
            $output .= '<div class="alert alert-success">اطلاعات با موفقیت ذخیره شد.</div>';
        } else {
            $output .= '<div class="alert alert-danger">کد تأیید نادرست است.</div>';
        }
    }

    // فرم ویرایش در صورت نیاز
    if (empty($data->first_name) || empty($data->phone) || empty($data->last_name) || isset($_GET['edit'])) {
        $output .= '<form method="post" class="border p-4 rounded shadow-sm bg-light">';
        $output .= '<div class="row g-3 align-items-end">';

// First Name
        $output .= '<div class="col-md-8">
    <label class="form-label">نام:</label>
    <input type="text" class="form-control" name="first_name" value="' . esc_attr($data->first_name) . '" required>
</div>';
        $output .= '<div class="col-md-2">
    <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="show_first_name" value="1" ' . checked($data->show_first_name, 1, false) . '>
        <label class="form-check-label">نمایش داده شود</label>
    </div>
</div>';

// Last Name
        $output .= '<div class="col-md-8">
    <label class="form-label">نام خانوادگی:</label>
    <input type="text" class="form-control" name="last_name" value="' . esc_attr($data->last_name) . '" required>
</div>';
        $output .= '<div class="col-md-2">
    <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="show_last_name" value="1" ' . checked($data->show_last_name, 1, false) . '>
        <label class="form-check-label">نمایش داده شود</label>
    </div>
</div>';

// Phone
        if ($data->phone==null)
        {
            $output .= '<div class="col-md-8">
    <label class="form-label">شماره موبایل:</label>
    <input type="text" class="form-control" name="phone" value="' . esc_attr($data->phone) . '" required>
</div>';
            $output .= '<div class="col-md-2">
</div>';
        }


        $output .= '</div>'; // end row

        $output .= '<div class="mt-4">
    <input type="submit" class="btn btn-primary" name="send_otp" value="ارسال کد تأیید">
</div>';

        $output .= '</form>';

        return $output;
    }

    // حالت مشاهده اطلاعات
    $output .= '<div class="qrcode-info card p-4 bg-white shadow-sm">';
    $output .= '<h4 class="mb-3">اطلاعات ثبت‌شده</h4>';

    if ($data->show_first_name) {
        $output .= '<p><strong>نام:</strong> ' . esc_html($data->first_name) . '</p>';
    }
    if ($data->show_last_name) {
        $output .= '<p><strong>نام خانوادگی:</strong> ' . esc_html($data->last_name) . '</p>';
    }
    if ($data->show_phone) {
        $output .= '<p><strong>شماره موبایل:</strong> ' . esc_html($data->phone) . '</p>';
    }

    $output .= '<p><strong>شناسه QR:</strong> ' . esc_html($data->unique_id) . '</p>';
    $output .= '<a href="?uid=' . urlencode($uid) . '&edit=true" class="btn btn-secondary btn-sm">درخواست ویرایش</a>';
    $output .= '</div></div>';

    // ثبت لاگ
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $location = get_location_by_ip($ip_address);

    $wpdb->insert($wpdb->prefix . 'qrcode_view_logs', [
        'uid'        => $uid,
        'ip_address' => $ip_address,
        'location'   => $location,
        'user_agent' => $user_agent,
        'viewed_at'  => current_time('mysql'),
    ]);

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
