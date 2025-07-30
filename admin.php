<?php
require_once plugin_dir_path(__FILE__) . 'jdf.php';
use Dompdf\Dompdf;
use Dompdf\Options;

function qrcode_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'qrcode_users';
    $per_page = get_user_option('edit_qrcode_per_page');
    if (!$per_page || $per_page < 1) {
        $per_page = 20; // مقدار پیش‌فرض
    }
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $total_pages = ceil($total_items / $per_page);

    add_filter('set-screen-option', function ($status, $option, $value) {
        return $value;
    }, 10, 3);

    add_action('admin_menu', function () {
        $hook = add_menu_page('qrcode-users'); // آدرس صفحه پلاگین
        add_action("load-$hook", function () {
            add_screen_option('per_page', [
                'label' => 'تعداد نمایش در هر صفحه',
                'default' => 20,
                'option' => 'edit_qrcode_per_page'
            ]);
        });
    });
    // حذف
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
        $wpdb->delete($table, ['id' => intval($_GET['id'])]);
        echo '<div class="updated"><p>رکورد حذف شد.</p></div>';
    }

    // ویرایش
    if (isset($_POST['edit_id'])) {
        $wpdb->update($table, [
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'phone'      => sanitize_text_field($_POST['phone']),
        ], ['id' => intval($_POST['edit_id'])]);
        echo '<div class="updated"><p>ویرایش با موفقیت انجام شد.</p></div>';
    }

    // افزودن
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new'])) {
        $wpdb->insert($table, [
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'phone'      => sanitize_text_field($_POST['phone']),
            //'unique_id'  => uniqid('qr_', true)
            'unique_id'  => 'np_'. (string) round(microtime(true) * 1000)
        ]);
        echo '<div class="updated"><p>کاربر اضافه شد.</p></div>';
    }

    // جستجو
    $where = '1=1';
    if (!empty($_GET['search'])) {
        $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['search'])) . '%';
        $where .= $wpdb->prepare(" AND (first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR unique_id LIKE %s)", $search, $search, $search, $search);
    }
    if (!empty($_GET['from_date'])) {
        list($jy, $jm, $jd) = explode('/', $_GET['from_date']);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $from = "$gy-$gm-$gd 00:00:00";
        $where .= $wpdb->prepare(" AND created_at >= %s", $from);
    }
    if (!empty($_GET['to_date'])) {
        list($jy, $jm, $jd) = explode('/', $_GET['to_date']);
        list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
        $to = "$gy-$gm-$gd 23:59:59";
        $where .= $wpdb->prepare(" AND created_at <= %s", $to);
    }

    $users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        )
    );

    $edit_mode = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']);
    $edit_user = $edit_mode ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $_GET['id'])) : null;
    ?>
    <div class="wrap">
        <h1>مدیریت QRCodeها</h1>

        <!-- فرم جستجو -->
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="qrcode-users" />
            <input type="text" name="search" placeholder="جستجو بر اساس نام، شماره یا QR ID" value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>" />
            <input type="text" name="from_date" placeholder="از تاریخ (مثلاً 1403/01/01)" value="<?php echo isset($_GET['from_date']) ? esc_attr($_GET['from_date']) : ''; ?>" />
            <input type="text" name="to_date" placeholder="تا تاریخ" value="<?php echo isset($_GET['to_date']) ? esc_attr($_GET['to_date']) : ''; ?>" />
            <button type="submit" class="button">فیلتر</button>
            <a href="<?php echo admin_url('admin.php?page=qrcode-users'); ?>" class="button">نمایش همه</a>
            <?php if (!empty($_GET['search']) || !empty($_GET['from_date']) || !empty($_GET['to_date'])): ?>
                <a href="<?php echo admin_url('admin.php?page=qrcode-users&action=export_pdf&' . http_build_query($_GET)); ?>" class="button button-primary" target="_blank">📄 خروجی PDF</a>
            <?php endif; ?>
        </form>


        <!-- فرم افزودن یا ویرایش -->
        <?php if ($edit_mode && $edit_user): ?>
            <h2>ویرایش کاربر</h2>
            <form method="post">
                <input type="hidden" name="edit_id" value="<?php echo esc_attr($edit_user->id); ?>">
                <input type="text" name="first_name" value="<?php echo esc_attr($edit_user->first_name); ?>" required>
                <input type="text" name="last_name" value="<?php echo esc_attr($edit_user->last_name); ?>" required>
                <input type="text" name="phone" value="<?php echo esc_attr($edit_user->phone); ?>" required>
                <button type="submit" class="button button-primary">ذخیره تغییرات</button>
                <a href="<?php echo admin_url('admin.php?page=qrcode-users'); ?>" class="button">انصراف</a>
            </form>
        <?php else: ?>
            <h2>افزودن جدید</h2>
            <form method="post">
                <input type="hidden" name="add_new" value="1">
                <input type="text" name="first_name" placeholder="نام" required>
                <input type="text" name="last_name" placeholder="نام خانوادگی" required>
                <input type="text" name="phone" placeholder="شماره موبایل" required>
                <button type="submit" class="button button-primary">افزودن</button>
            </form>
        <?php endif; ?>
        <h2>تولید دستی QRCodeها</h2>
        <form method="post">
            <input type="number" name="bulk_generate_count" min="1" required placeholder="تعداد تولید (مثلاً 10)">
            <button type="submit" class="button button-primary">تولید کن</button>
        </form>
        <?php
        if (isset($_POST['bulk_generate_count']) && is_numeric($_POST['bulk_generate_count'])) {
            $count = intval($_POST['bulk_generate_count']);
            global $wpdb;
            $table_name = $wpdb->prefix . 'qrcode_pet_manager';

            for ($i = 0; $i < $count; $i++) {
                $unique_code ='np_'. (string) round(microtime(true) * 1000);
                $wpdb->insert($table, [
                    //'first_name' => sanitize_text_field($_POST['first_name']),
                    //'last_name'  => sanitize_text_field($_POST['last_name']),
                    //'phone'      => sanitize_text_field($_POST['phone']),
                    //'unique_id'  => uniqid('qr_', true)
                    'unique_id'  => 'np_'. (string) round(microtime(true) * 1000)
                ]);
                usleep(10000); // 10 میلی‌ثانیه تاخیر برای اطمینان از یکتا بودن
            }
            echo '<div class="updated"><p>' . esc_html($count) . ' QRCode با موفقیت تولید شد.</p></div>';
        }

        ?>
        <!-- جدول پلاک ها -->
        <h2 style="margin-top: 30px;">لیست پلاک ها</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th>نام</th>
                <th>شماره</th>
                <th>شناسه</th>
                <th>تاریخ (شمسی)</th>
                <th>QR Code</th>
                <th>عملیات</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($users) === 0): ?>
                <tr><td colspan="6">نتیجه‌ای یافت نشد.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $user): ?>
                <?php $link = site_url('/qrcode-info/?uid=' . $user->unique_id); ?>
                <tr>
                    <td><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></td>
                    <td><?php echo esc_html($user->phone); ?></td>
                    <td><?php echo esc_html($user->unique_id); ?></td>
                    <td><?php echo esc_html(to_jalali($user->created_at)); ?></td>
                    <td><img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?php echo urlencode($link); ?>" /></td>
                    <td>
                        <a href="?page=qrcode-users&action=edit&id=<?php echo $user->id; ?>" class="button small">ویرایش</a>
                        <a href="?page=qrcode-users&action=delete&id=<?php echo $user->id; ?>" class="button small" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfooter>

            </tfooter>
        </table>
        <?php
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('« قبلی'),
            'next_text' => __('بعدی »'),
            'total' => $total_pages,
            'current' => $paged
        ]);
        echo '</div></div>';

        ?>
    </div>
    <?php
    if (isset($_GET['action']) && $_GET['action'] === 'export_pdf') {
        require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';

// جلوگیری از هرگونه خروجی قبلی
        if (ob_get_length()) {
            ob_end_clean();
        }

        ob_start();
        echo '<table border="1" cellpadding="5" cellspacing="0"><thead><tr>
       <th>QR</th>
    </tr></thead><tbody>';
        foreach ($users as $user) {
            $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode(site_url('/qrcode-info/?uid=' . $user->unique_id));
            // دریافت تصویر و تبدیل به base64
            $imageData = download_image($qr_url);
            $base64 = base64_encode($imageData);
            $src = 'data:image/png;base64,' . $base64;

            echo '<tr>';
            echo '<td><img src="' . $qr_url . '" /></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $html = ob_get_clean();
        $options = new Options();

        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // پاک کردن هر چیزی که ممکنه قبلاً در بافر مونده باشه
        if (ob_get_length()) {
            ob_end_clean();
        }


        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename='qrcodes.pdf'");
        echo $dompdf->output();
        exit;
    }

}
function download_image($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // برای جلوگیری از خطاهای SSL
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}
