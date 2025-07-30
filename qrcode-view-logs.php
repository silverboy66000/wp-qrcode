<?php
if (!defined('ABSPATH')) exit; // جلوگیری از دسترسی مستقیم

function qrcode_view_logs_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'qrcode_view_logs';
    $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY viewed_at DESC LIMIT 100");

    ?>
    <div class="wrap">
        <h1>تاریخچه بازدیدها</h1>
        <table class="widefat fixed striped">
            <thead>
            <tr>
                <th>UID</th>
                <th>IP</th>
                <th>موقعیت</th>
                <th>مرورگر</th>
                <th>زمان مشاهده</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->uid); ?></td>
                    <td><?php echo esc_html($log->ip_address); ?></td>
                    <td><?php echo esc_html($log->location); ?></td>
                    <td><?php echo esc_html(substr($log->user_agent, 0, 50)) . '...'; ?></td>
                    <td><?php echo esc_html($log->viewed_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
