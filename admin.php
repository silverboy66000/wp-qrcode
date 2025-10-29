<?php
require_once plugin_dir_path(__FILE__) . 'jdf.php';
use Dompdf\Dompdf;
use Dompdf\Options;
// افزایش محدودیت‌ها
ini_set('max_execution_time', 300); // 5 دقیقه
ini_set('memory_limit', '512M');

// هندل ذخیره تنظیمات صفحه
add_filter('set-screen-option', function ($status, $option, $value) {
    if ('edit_qrcode_per_page' === $option) return (int) $value;
    return $status;
}, 10, 3);


function qrcode_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'qrcode_users';

    // خواندن مقدار از تنظیمات کاربر
    $per_page = (int) get_user_option('edit_qrcode_per_page');
    if ($per_page < 1) {
        $per_page = 20; // مقدار پیش‌فرض
    }

    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $total_pages = ceil($total_items / $per_page);

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
    if (!empty($_GET['has_phone'])) {
        $where .= " AND phone <> ''";
    }

    $users = $wpdb->get_results(
            $wpdb->prepare(
                    "SELECT * FROM $table WHERE $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
            )
    );
    $Allusers = $wpdb->get_results(
            "SELECT * FROM $table WHERE $where ORDER BY created_at DESC"
    );

// گرفتن تعداد کل رکوردها
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");

    $edit_mode = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']);
    $edit_user = $edit_mode ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $_GET['id'])) : null;
    ?>
    <script>
        $("#elementId, .elementClass").persianDatepicker();
    </script>

    <script>
        jQuery(document).ready(function($){
            $("#datePicker").persianDatepicker({
                format: 'YYYYMM/DD'
            });
            $("#pdpF2").persianDatepicker({
                formatDate: "YYYY/MM/DD"
            });
        });
    </script>
    <h1>مدیریت QRCodeها</h1>
    <hr>
    <!-- فرم جستجو -->
    <form method="get" style="margin-bottom: 20px;">
        <input type="hidden" name="page" value="qrcode-users" />
        <input type="text" name="search" placeholder="جستجو بر اساس نام، شماره یا QR ID" value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>" />
        <input type="text" name="from_date" id="datePicker" value="<?php echo isset($_GET['from_date']) ? esc_attr($_GET['from_date']) : ''; ?>"/>
        <input type="text" name="to_date" id="pdpF2" value="<?php echo isset($_GET['to_date']) ? esc_attr($_GET['to_date']) : ''; ?>" />
        <label style="margin-right:10px;">
            <input type="checkbox" name="has_phone" value="1" <?php checked(!empty($_GET['has_phone'])); ?> />
            فقط کاربرانی که شماره موبایل دارند
        </label>
        <button type="submit" class="button">فیلتر</button>
        <a href="<?php echo admin_url('admin.php?page=qrcode-users'); ?>" class="button">نمایش همه</a>
        <?php if (!empty($_GET['search']) || !empty($_GET['from_date']) || !empty($_GET['to_date']) || !empty($_GET['has_phone'])): ?>
            <a href="<?php echo admin_url('admin.php?page=qrcode-users&action=export_pdf&' . http_build_query($_GET)); ?>" class="button button-primary" target="_blank">📄 دریافت فایل PDF</a>
        <?php endif; ?>
    </form>
    <hr>
    <!-- فرم افزودن یا ویرایش -->
    <?php if ($edit_mode && $edit_user): ?>
        <h2>ویرایش اطلاعات پت شماره <?php echo esc_attr($edit_user->id); ?></h2>
        <form method="post">
            <input type="hidden" name="edit_id" value="<?php echo esc_attr($edit_user->id); ?>">
            <input type="text" name="first_name" value="<?php echo esc_attr($edit_user->first_name); ?>" required>
            <input type="text" name="last_name" value="<?php echo esc_attr($edit_user->last_name); ?>" required>
            <input type="text" name="phone" value="<?php echo esc_attr($edit_user->phone); ?>" required>
            <button type="submit" class="button button-primary">ذخیره تغییرات</button>
            <a href="<?php echo admin_url('admin.php?page=qrcode-users'); ?>" class="button">انصراف</a>
        </form>
        <hr>
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
    <hr>
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
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/qrcodes/';

        for ($i = 0; $i < $count; $i++) {
            $unique_code ='np_'. (string) round(microtime(true) * 1000);
            $file_path = $dir . $unique_code . '.png';

            $wpdb->insert($table, [
                    'unique_id'  => $unique_code
            ]);
            $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=" . urlencode(site_url('/qrcode-info/?uid=' . $unique_code));
            $imageData = download_image($qr_url);
            file_put_contents($file_path, $imageData);
            usleep(10000);
        }
        echo '<div class="updated"><p>' . esc_html($count) . ' QRCode با موفقیت تولید شد.</p></div>';
    }

    ?>
    <hr>
    <!-- جدول پلاک ها -->
    <h2 style="margin-top: 30px;">تعداد نتایج لیست پلاک ها  : <?php echo $total;?> </h2>
    <hr>
    <form >
        <input type="hidden" name="page" value="qrcode-users">
        <select name="type">
            <option>انتخاب وضعیت</option>
            <option value="pdf">©️ PDF انتخاب‌شده‌ها</option>
            <option value="delete">❌ حذف انتخاب‌شده‌ها </option>
        </select>
        <button type="submit" name="export_selected_pdf" class="button button-primary">✅ اجرا</button>
        <br>
        <br>
        <table class="wp-list-table widefat fixed striped">
            <thead>
            <tr>
                <th><input type="checkbox" id="select-all"></th>
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

                <?php
                $link = site_url('/qrcode-info/?uid=' . $user->unique_id);
                $upload_dir = wp_upload_dir();
                $dir = $upload_dir['basedir'] . '/qrcodes/';
                $url = $upload_dir['baseurl'] . '/qrcodes/' . $user->unique_id . '.png';
                ?>
                <tr>
                    <td><input type="checkbox" name="selected_ids[]" value="<?php echo esc_attr($user->id); ?>"></td>
                    <td><?php echo esc_html($user->first_name . ' ' . $user->last_name); ?></td>
                    <td><?php echo esc_html($user->phone); ?></td>
                    <td><?php echo esc_html(getUnique_id($user)); ?></td>
                    <td style="direction: ltr"><?php echo esc_html(gregorian_to_jalali((int)explode('-',$user->created_at)[0],(int)explode('-',$user->created_at)[1],(int)explode('-',$user->created_at)[2],' / ')); ?></td>
                    <td> <?php if (file_exists($dir . $user->unique_id . '.png')): ?>
                            <img src="<?php echo esc_url($url); ?>" style="width:80px; height:80px;" />
                        <?php else: ?>
                            <span>QR موجود نیست</span>
                        <?php endif; ?></td>
                    <td>
                        <a href="?page=qrcode-users&action=edit&id=<?php echo $user->id; ?>" class="button small">ویرایش</a>
                        <a href="?page=qrcode-users&action=delete&id=<?php echo $user->id; ?>" class="button small" onclick="return confirm('آیا مطمئن هستید؟')">حذف</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </form>
    <?php
    echo '<div class="tablenav"><div class="">';
    echo paginate_links([
            'class'=>'button small',
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('« قبلی'),
            'next_text' => __('بعدی »'),
            'total' => $total_pages,
            'current' => $paged
    ]);
    echo '</div></div>';

    ?>
    <script>
        document.getElementById("select-all").addEventListener("click", function(e) {
            const checked = e.target.checked;
            document.querySelectorAll("input[name='selected_ids[]']").forEach(cb => cb.checked = checked);
          //  document.getElementById('exportBTN').style.display = checked ? 'block' : 'none';
        });
    </script>
    <style>
        .page-numbers{
            display: inline-block;
            text-decoration: none;
            font-size: 13px;
            line-height: 2.15384615;
            min-height: 30px;
            margin: 0;
            padding: 0 10px;
            cursor: pointer;
            border-width: 1px;
            border-style: solid;
            -webkit-appearance: none;
            border-radius: 3px;
            white-space: nowrap;
            box-sizing: border-box;
        }
        /*#exportBTN {*/
        /*    display: none;*/
        /*}*/
    </style>
    </div>
    <?php
    if (isset($_GET['action']) && $_GET['action'] === 'export_pdf') {
        require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/qrcodes/';

        if ( ! file_exists($dir) ) {
            wp_mkdir_p($dir);
        }

        if (ob_get_length()) {
            ob_end_clean();
        }

        ob_start();
        echo '<table border="1" cellpadding="5" cellspacing="0"><thead><tr>
       <th>QR</th>
        </tr></thead><tbody>';

        foreach ($Allusers as $user) {
            $file_url  = $upload_dir['baseurl'] . '/qrcodes/' . $user->unique_id . '.png';
            echo '<tr>';
            echo '<td><img src="' . $file_url . '" /></td>';
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

        if (ob_get_length()) {
            ob_end_clean();
        }

        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename='qrcodes.pdf'");
        echo $dompdf->output();
        exit;
    }
}

function getUnique_id($user)
{
    return $user->unique_id;
}

function download_image($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}


// ----------------------
// خروجی PDF انتخابی‌ها
// ----------------------
if (isset($_GET['export_selected_pdf']) && !empty($_GET['selected_ids'])) {

    global $wpdb;
    require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
    $upload_dir = wp_upload_dir();
    $dir = $upload_dir['basedir'] . '/qrcodes/';
    $table = $wpdb->prefix . 'qrcode_users';
    $ids = array_map('intval', $_GET['selected_ids']);
    $ids_placeholders = implode(',', array_fill(0, count($ids), '%d'));

    if ($_GET['type'] === 'pdf') {

        $query = $wpdb->prepare("SELECT * FROM $table WHERE id IN ($ids_placeholders)", ...$ids);
        $selected_users = $wpdb->get_results($query);

        if (ob_get_length()) ob_end_clean();

        ob_start();
        echo '<table border="1" cellpadding="5" cellspacing="0"><tbody>';
        foreach ($selected_users as $user) {
            $file_url  = $upload_dir['baseurl'] . '/qrcodes/' . $user->unique_id . '.png';

            //$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode(site_url('/qrcode-info/?uid=' . $user->unique_id));
            // $imageData = download_image($qr_url);
            // $src = 'data:image/png;base64,' . base64_encode($imageData);
            echo '<tr><td style="text-align:center;"><img src="' . $file_url . '"><hr></td></tr>';
        }
        echo '</tbody></table>';
        $html = ob_get_clean();

        require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header("Content-Type: application/pdf");
        header("Content-Disposition: inline; filename='selected_qrcodes.pdf'");
        echo $dompdf->output();
        exit;

    }
    if ($_GET['type'] === 'delete') {

        $query = $wpdb->prepare("DELETE FROM $table WHERE id IN ($ids_placeholders)", ...$ids);
        $result = $wpdb->query($query);

        if ($result !== false) {
            echo "<script>alert('رکورد(ها) با موفقیت حذف شد.'); history.back();</script>";
        } else {
            echo "<script>alert('خطا در حذف رکورد(ها).'); history.back();</script>";
        }
    exit;
    }


}
?>
