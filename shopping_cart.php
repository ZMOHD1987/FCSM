 <?php
// shopping_cart.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/shopping_cart_calculate.php';

$conn = connectDB();
if ($conn === null) {
    die("خطأ في الاتصال بقاعدة البيانات. الرجاء المحاولة لاحقاً.");
}

$available_langs = ['ar', 'en', 'hi', 'ur'];
$lang = 'ar';
if (isset($_GET['lang']) && in_array($_GET['lang'], $available_langs)) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (isset($_SESSION['lang'])) {
    $lang = $_SESSION['lang'];
}
$dir = ($lang === 'ar' || $lang === 'ur') ? 'rtl' : 'ltr';
$t = $translations[$lang] ?? $translations['en'] ?? [];

function html_safe($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function getShippingCompanies($conn, $to_country_id = null, $to_city_id = null) {
    $sql = "
        SELECT DISTINCT tps.id, tps.company_name, tsp.price
        FROM third_party_services tps
        LEFT JOIN third_party_shipping_prices tsp ON tps.id = tsp.service_id
        WHERE tps.service_type = 'delivery' AND tps.is_active = 1";
    
    $params = [];
    $types = '';
    
    if ($to_country_id && $to_city_id) {
        $sql .= " AND tsp.to_country_id = ? AND tsp.to_city_id = ?";
        $params = [$to_country_id, $to_city_id];
        $types = 'ii';
    }
    
    error_log("getShippingCompanies SQL: $sql, to_country_id: $to_country_id, to_city_id: $to_city_id");
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed for shipping companies query: " . $conn->error);
        return [];
    }
    
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Execute failed for shipping companies query: " . $stmt->error);
        $stmt->close();
        return [];
    }
    
    $result = $stmt->get_result();
    $companies = [];
    while ($row = $result->fetch_assoc()) {
        $companies[] = [
            'id' => $row['id'],
            'company_name' => $row['company_name'],
            'price' => $row['price'] !== null ? floatval($row['price']) : 0.0
        ];
    }
    $stmt->close();
    
    error_log("Shipping companies fetched: " . json_encode($companies));
    return $companies;
}

function hasActivePaymentGateway($conn, $vendor_id) {
    $stmt = $conn->prepare("SELECT user_id FROM vendors WHERE id = ?");
    $stmt->bind_param("i", $vendor_id);
    if (!$stmt->execute()) {
        error_log("Failed to fetch vendor user_id: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result()->fetch_assoc();
    $vendor_user_id = $result['user_id'] ?? null;
    $stmt->close();
    
    if (!$vendor_user_id) {
        error_log("No user_id found for vendor_id: $vendor_id");
        return false;
    }
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM vendor_payment_gateways WHERE user_id = ? AND is_active = 1");
    $stmt->bind_param("i", $vendor_user_id);
    if (!$stmt->execute()) {
        error_log("Failed to check payment gateway for user_id $vendor_user_id: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['count'] > 0;
}

function getCountries($conn, $lang_code) {
    $stmt = $conn->prepare("SELECT c.id, ct.name FROM countries c JOIN country_translations ct ON c.id = ct.country_id WHERE ct.language_code = ? AND c.is_active = 1 ORDER BY ct.name ASC");
    $stmt->bind_param("s", $lang_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $countries = [];
    while ($row = $result->fetch_assoc()) {
        $countries[] = $row;
    }
    $stmt->close();
    return $countries;
}

function getUserPoints($conn, $user_id, $vendor_id) {
    $stmt = $conn->prepare("SELECT points_balance FROM customer_vendor_points WHERE user_id = ? AND vendor_id = ?");
    $stmt->bind_param("ii", $user_id, $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['points_balance'] ?? 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = $_SESSION['user_id'] ?? null;
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {
        case 'update_quantity_ajax':
        case 'recalculate_totals':
            $orderId = intval($_POST['order_id'] ?? 0);
            $deliveryType = $_POST['delivery_type'] ?? 'pickup';
            $shippingCompanyId = !empty($_POST['shipping_company_id']) ? intval($_POST['shipping_company_id']) : null;
            $toCountryId = !empty($_POST['to_country_id']) ? intval($_POST['to_country_id']) : null;
            $toCityId = !empty($_POST['to_city_id']) ? intval($_POST['to_city_id']) : null;
            $dest_lat = !empty($_POST['lat']) ? floatval($_POST['lat']) : null;
            $dest_lng = !empty($_POST['lng']) ? floatval($_POST['lng']) : null;
            $points_to_use = intval($_POST['points_to_use'] ?? 0);

            if ($_POST['action'] === 'update_quantity_ajax') {
                $product_id = intval($_POST['product_id'] ?? 0);
                $new_quantity = intval($_POST['quantity'] ?? 0);

                if ($new_quantity <= 0) {
                    $response['message'] = html_safe($t['quantity_must_be_positive'] ?? 'الكمية يجب أن تكون أكبر من صفر.');
                    echo json_encode($response);
                    exit;
                }

                $updateResult = updateOrderItemQuantity($conn, $orderId, $product_id, $new_quantity);
                if (!$updateResult['success']) {
                    $response['message'] = $updateResult['error'];
                    echo json_encode($response);
                    exit;
                }
                $response['total_price'] = number_format($updateResult['new_total_price'], 2, '.', '');
            }

            if ($orderId > 0) {
                $calculationResult = calculateOrderTotals($conn, $orderId, $deliveryType, $shippingCompanyId, $toCountryId, $toCityId, $dest_lat, $dest_lng, $points_to_use);
                if (isset($calculationResult['error'])) {
                    $response['message'] = $calculationResult['error'];
                    echo json_encode($response);
                    exit;
                }

                $response['success'] = true;
                $response['subtotal'] = number_format($calculationResult['subtotal'], 2, '.', '');
                $response['tax'] = number_format($calculationResult['tax'], 2, '.', '');
                $response['shipping'] = number_format($calculationResult['shipping'], 2, '.', '');
                $response['points_discount'] = number_format($calculationResult['points_discount'], 2, '.', '');
                $response['total'] = number_format($calculationResult['total'], 2, '.', '');
                $response['message'] = html_safe($t['totals_updated_successfully'] ?? 'تم تحديث الإجماليات بنجاح.');

                $stmt = $conn->prepare("SELECT COUNT(DISTINCT product_id) FROM order_items WHERE order_id = ?");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $response['distinct_items_count'] = $stmt->get_result()->fetch_row()[0] ?? 0;
                $stmt->close();

                $stmt = $conn->prepare("SELECT SUM(quantity) FROM order_items WHERE order_id = ?");
                $stmt->bind_param("i", $orderId);
                $stmt->execute();
                $response['total_items_count'] = $stmt->get_result()->fetch_row()[0] ?? 0;
                $stmt->close();
            } else {
                $response['message'] = html_safe($t['incomplete_data'] ?? 'بيانات الحساب غير مكتملة.');
            }
            echo json_encode($response);
            exit;

        case 'fetch_cities':
            $country_id = intval($_POST['country_id'] ?? 0);
            $cities = [];
            if ($country_id > 0) {
                $stmt = $conn->prepare("SELECT c.id, ct.name FROM cities c JOIN city_translations ct ON c.id = ct.city_id WHERE c.country_id = ? AND ct.language_code = ? AND c.is_active = 1 ORDER BY ct.name ASC");
                $stmt->bind_param("is", $country_id, $lang);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $cities[] = $row;
                }
                $stmt->close();
            }
            echo json_encode(['success' => true, 'cities' => $cities]);
            exit;

        case 'fetch_shipping_companies':
            $to_country_id = intval($_POST['to_country_id'] ?? 0);
            $to_city_id = intval($_POST['to_city_id'] ?? 0);
            
            if ($to_country_id && $to_city_id) {
                $companies = getShippingCompanies($conn, $to_country_id, $to_city_id);
                echo json_encode(['success' => true, 'companies' => $companies]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Country and city are required']);
            }
            exit;

        case 'update_order_location':
            $orderId = intval($_POST['order_id'] ?? 0);
            $lat = floatval($_POST['lat'] ?? 0.0);
            $lng = floatval($_POST['lng'] ?? 0.0);
            $delivery_address = $_POST['delivery_address'] ?? '';

            if ($orderId > 0) {
                $stmt = $conn->prepare("UPDATE orders SET lat = ?, lng = ?, delivery_address = ? WHERE id = ?");
                $stmt->bind_param("ddsi", $lat, $lng, $delivery_address, $orderId);
                $stmt->execute();
                $stmt->close();
                $response['success'] = true;
                $response['message'] = 'Location updated successfully';
            } else {
                $response['message'] = 'Invalid order ID';
            }
            echo json_encode($response);
            exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'], $_POST['product_id'])) {
    $user_id = $_SESSION['user_id'] ?? null;
    $product_id = intval($_POST['product_id']);
    
    if (!$user_id) {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + 1;
    } else {
        $stmt = $conn->prepare("SELECT price, discount_price, vendor_id FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product_details = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($product_details) {
            $unit_price = $product_details['discount_price'] ?? $product_details['price'];
            $vendor_id = $product_details['vendor_id'];

            $stmt = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND order_status = 'pending'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $order_id = $order['id'] ?? null;
            $stmt->close();

            if (!$order_id) {
                $stmt = $conn->prepare("INSERT INTO orders (user_id, vendor_id, order_status, total_amount, payment_status) VALUES (?, ?, 'pending', 0, 'pending')");
                $stmt->bind_param("ii", $user_id, $vendor_id);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();
            }

            $stmt = $conn->prepare("SELECT quantity FROM order_items WHERE order_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $order_id, $product_id);
            $stmt->execute();
            $item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $quantity = ($item['quantity'] ?? 0) + 1;
            $total_item_price = $unit_price * $quantity;

            if ($item) {
                $stmt = $conn->prepare("UPDATE order_items SET quantity = ?, total_price = ? WHERE order_id = ? AND product_id = ?");
                $stmt->bind_param("idii", $quantity, $total_item_price, $order_id, $product_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiidd", $order_id, $product_id, $quantity, $unit_price, $total_item_price);
            }
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE orders SET total_amount = (SELECT COALESCE(SUM(total_price), 0) FROM order_items WHERE order_id = ?) WHERE id = ?");
            $stmt->bind_param("ii", $order_id, $order_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: shopping_cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $user_id = $_SESSION['user_id'] ?? null;
    $product_id = intval($_POST['product_id'] ?? 0);

    if ($user_id && $product_id) {
        $stmt_order = $conn->prepare("SELECT id FROM orders WHERE user_id = ? AND order_status = 'pending' ORDER BY id DESC LIMIT 1");
        $stmt_order->bind_param("i", $user_id);
        $stmt_order->execute();
        $order_id_to_delete_from = $stmt_order->get_result()->fetch_row()[0] ?? null;
        $stmt_order->close();

        if ($order_id_to_delete_from) {
            $stmt_delete = $conn->prepare("DELETE FROM order_items WHERE order_id = ? AND product_id = ?");
            $stmt_delete->bind_param("ii", $order_id_to_delete_from, $product_id);
            $stmt_delete->execute();
            $stmt_delete->close();
        }
    }
    header("Location: shopping_cart.php");
    exit();
}

$user_id = $_SESSION['user_id'] ?? null;
$cart_items = [];
$total_cost = 0;
$total_tax = 0;
$shipping_fee = 0;
$order_total = 0;
$to_country_prefill = null;
$to_city_prefill = null;
$third_party_services = [];
$countries = getCountries($conn, $lang);

$user_default_country_id = null;
$user_default_city_id = null;
$delivery_address = '';
$delivery_lat = '';
$delivery_lng = '';
$order_id_display = null;
$number_of_distinct_items = 0;
$delivery_type_prefill = 'pickup';
$shipping_company_prefill = null;

if ($user_id) {
    $stmt_user = $conn->prepare("SELECT country_id, city_id FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_info = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();
    if ($user_info) {
        $user_default_country_id = $user_info['country_id'];
        $user_default_city_id = $user_info['city_id'];
    }

    $stmt_order = $conn->prepare("SELECT id, delivery_type, shipping_company_id, to_country_id, to_city_id, delivery_address, lat, lng, vendor_id FROM orders WHERE user_id = ? AND order_status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt_order->bind_param("i", $user_id);
    $stmt_order->execute();
    $order_info = $stmt_order->get_result()->fetch_assoc();
    $stmt_order->close();
    
    $order_id_pending = $order_info['id'] ?? null;
    $vendor_id = $order_info['vendor_id'] ?? null;
    $delivery_type_prefill = $order_info['delivery_type'] ?? 'pickup';
    $shipping_company_prefill = $order_info['shipping_company_id'] ?? null;
    $to_country_prefill = $order_info['to_country_id'] ?? $user_default_country_id;
    $to_city_prefill = $order_info['to_city_id'] ?? $user_default_city_id;
    $delivery_address = $order_info['delivery_address'] ?? '';
    $delivery_lat = $order_info['lat'] ?? '';
    $delivery_lng = $order_info['lng'] ?? '';
    
    $third_party_services = getShippingCompanies($conn, $to_country_prefill, $to_city_prefill);
    
    if ($order_id_pending) {
        $sql = "
            SELECT
                oi.product_id,
                oi.quantity,
                oi.unit_price,
                oi.total_price,
                p.product_name,
                p.brand,
                p.discount_price,
                p.price as original_price,
                (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.id LIMIT 1) as image_url,
                o.id as order_id_display
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN products p ON oi.product_id = p.id
            WHERE o.id = ? AND o.order_status = 'pending'
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $order_id_pending);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $product_id = $row['product_id'];
            $row['properties'] = [];
            $stmt_prop = $conn->prepare("SELECT property_name, property_value FROM product_properties WHERE product_id = ?");
            $stmt_prop->bind_param("i", $product_id);
            $stmt_prop->execute();
            $properties_result = $stmt_prop->get_result();
            while ($prop_row = $properties_result->fetch_assoc()) {
                $row['properties'][] = $prop_row;
            }
            $stmt_prop->close();
            $cart_items[] = $row;
            $order_id_display = $row['order_id_display'];
        }
        $stmt->close();

        if (!empty($cart_items)) {
            $calculationResult = calculateOrderTotals($conn, $order_id_pending, $delivery_type_prefill, $shipping_company_prefill, $to_country_prefill, $to_city_prefill, $delivery_lat, $delivery_lng);
            $total_cost = $calculationResult['subtotal'] ?? 0;
            $total_tax = $calculationResult['tax'] ?? 0;
            $shipping_fee = $calculationResult['shipping'] ?? 0;
            $order_total = $calculationResult['total'] ?? 0;
        }
    }
} else {
    if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
        $product_ids = array_keys($_SESSION['cart']);
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "
            SELECT
                p.id as product_id,
                p.product_name,
                p.brand,
                p.discount_price,
                p.price as original_price,
                (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.id LIMIT 1) as image_url
            FROM products p
            WHERE p.id IN ($placeholders)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $product_id = $row['product_id'];
            $row['quantity'] = $_SESSION['cart'][$product_id];
            $row['unit_price'] = $row['discount_price'] ?? $row['original_price'];
            $row['total_price'] = $row['unit_price'] * $row['quantity'];
            $row['properties'] = [];
            $stmt_prop = $conn->prepare("SELECT property_name, property_value FROM product_properties WHERE product_id = ?");
            $stmt_prop->bind_param("i", $product_id);
            $stmt_prop->execute();
            $properties_result = $stmt_prop->get_result();
            while ($prop_row = $properties_result->fetch_assoc()) {
                $row['properties'][] = $prop_row;
            }
            $stmt_prop->close();
            $cart_items[] = $row;
        }
        $stmt->close();

        if (!empty($cart_items)) {
            $calculationResult = calculateOrderTotals($conn, null, $delivery_type_prefill, $shipping_company_prefill, $to_country_prefill, $to_city_prefill, $delivery_lat, $delivery_lng, 0);
            $total_cost = $calculationResult['subtotal'];
            $total_tax = $calculationResult['tax'];
            $shipping_fee = $calculationResult['shipping'];
            $order_total = $calculationResult['total'];
        }
    }
    if (empty($cart_items)) {
        header('Location: login.php');
        exit;
    }
}

$number_of_distinct_items = count($cart_items);

$total_cart_items_count = 0;
if ($order_id_display) {
    $stmt = $conn->prepare("SELECT SUM(quantity) FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i", $order_id_display);
    $stmt->execute();
    $total_cart_items_count = $stmt->get_result()->fetch_row()[0] ?? 0;
    $stmt->close();
} elseif (isset($_SESSION['cart'])) {
    $total_cart_items_count = array_sum($_SESSION['cart']);
}

$profile_link = '';
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'customer') {
        $profile_link = 'https://mzmz.wuaze.com/platform/admin/edit_client.php';
    } elseif ($_SESSION['role'] === 'vendor') {
        $profile_link = 'https://mzmz.wuaze.com/platform/vendor/vendor_dashboard.php';
    } elseif ($_SESSION['role'] === 'third_party_service') {
        $profile_link = 'https://mzmz.wuaze.com/platform/admin/edit_third_party.php';
    }
}

$has_payment_gateway = false;
if ($vendor_id) {
    $has_payment_gateway = hasActivePaymentGateway($conn, $vendor_id);
}

$available_points = 0;
if ($user_id && $vendor_id) {
    $available_points = getUserPoints($conn, $user_id, $vendor_id);
}
?>

<!DOCTYPE html>
<html lang="<?= html_safe($lang); ?>" dir="<?= html_safe($dir); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= html_safe($t['shopping_cart_title'] ?? 'سلة التسوق'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&family=Noto+Sans:wght@400;700&family=Noto+Sans+Arabic:wght@400;700&display=swap');
        body { font-family: 'Cairo', 'Noto Sans Arabic', 'Noto Sans', sans-serif; }
        .overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(107, 114, 128, 0.7);
            z-index: 20;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        #map { height: 300px; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <header class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4 py-3 md:py-4 flex items-center justify-between">
            <div class="flex items-center">
                <a href="index.php"><img src="https://mzmz.wuaze.com/platform/logo/main_logo.png?1755797953" alt="easyoffer24 Logo" class="h-8 md:h-10 w-auto"></a>
                <a href="index.php"><span class="logo-text text-lg md:text-xl font-extrabold ml-2 mr-2 text-gray-900">easyoffer24</span></a>
            </div>
            <nav class="hidden md:flex items-center space-x-4 space-x-reverse header-nav">
                <a href="<?= $profile_link ?>" class="flex items-center text-blue-600 font-semibold rounded-lg hover:bg-blue-50 px-3 py-2 transition duration-300">
                    <i class="fas fa-user-circle ml-2"></i> <?= html_safe($t['my_profile'] ?? 'ملفي الشخصي'); ?>
                </a>
                <a href="shopping_cart.php" class="relative bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 px-3 py-2 transition duration-300">
                    <i class="fas fa-shopping-cart"></i> <?= html_safe($t['shopping_cart'] ?? 'عربة التسوق'); ?>
                    <span class="absolute -top-2 -right-2 inline-flex items-center justify-center w-5 h-5 text-xs font-bold leading-none text-white bg-red-600 rounded-full" id="cart_count"><?= $total_cart_items_count; ?></span>
                </a>
            </nav>
            <button id="mobile-menu-button" class="md:hidden text-blue-700 text-2xl">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>
    <div id="mobile-menu" class="fixed top-0 <?= $dir === 'rtl' ? 'right-0 translate-x-full' : 'left-0 -translate-x-full' ?> w-64 h-full bg-white shadow-xl z-50 transform transition-transform duration-300 md:hidden">
        <div class="p-4 border-b flex justify-between items-center">
            <span class="text-xl font-bold">easyoffer24</span>
            <button id="close-menu-button" class="text-blue-500 hover:text-blue-800 text-xl"><i class="fas fa-times"></i></button>
        </div>
        <nav class="flex flex-col p-4 space-y-4 text-sm">
            <a href="<?= $profile_link ?>" class="font-semibold text-blue-700 hover:bg-blue-50 p-2 rounded-md"><i class="fas fa-user-circle ml-2"></i> <?= html_safe($t['my_profile'] ?? 'ملفي الشخصي'); ?></a>
            <a href="shopping_cart.php" class="font-semibold text-blue-700 hover:bg-blue-50 p-2 rounded-md"><i class="fas fa-shopping-cart ml-2"></i> <?= html_safe($t['shopping_cart'] ?? 'عربة التسوق'); ?></a>
        </nav>
    </div>

    <main class="container mx-auto px-4 py-6">
        <?php if (empty($cart_items)): ?>
            <div class="bg-white p-6 rounded-lg shadow-md text-center">
                <i class="fas fa-shopping-cart text-6xl text-gray-400 mb-4"></i>
                <h2 class="text-xl font-bold text-gray-900 mb-2"><?= html_safe($t['cart_empty'] ?? 'سلة التسوق فارغة'); ?></h2>
                <p class="text-gray-600 mb-6"><?= html_safe($t['cart_empty_message'] ?? 'ابدأ في إضافة المنتجات إلى سلتك الآن!'); ?></p>
                <a href="index.php" class="bg-blue-600 text-white py-3 px-6 rounded-lg font-bold hover:bg-blue-700 transition duration-300">
                    <?= html_safe($t['continue_shopping'] ?? 'العودة للتسوق'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-4">
                    <?php foreach ($cart_items as $item): ?>
                        <div class="bg-white p-4 rounded-lg shadow-md flex flex-col md:flex-row items-start md:items-center space-y-4 md:space-y-0 md:space-x-4 md:space-x-reverse">
                            <img src="<?= html_safe($item['image_url'] ?? 'https://mzmz.wuaze.com/platform/'); ?>" alt="<?= html_safe($item['product_name']); ?>" class="w-32 h-32 object-contain rounded-md border border-gray-200">
                            <div class="flex-grow">
                                <h3 class="font-bold text-gray-900 text-lg"><?= html_safe($item['product_name']); ?></h3>
                                <p class="text-sm text-gray-500 mt-1"><?= html_safe($t['product_number'] ?? 'رقم المنتج'); ?>: #<?= html_safe($item['product_id']); ?></p>
                                <p class="text-sm text-gray-500 mt-1"><?= html_safe($t['brand'] ?? 'العلامة التجارية'); ?>: <?= html_safe($item['brand'] ?? ''); ?></p>
                                <?php if (!empty($item['properties'])): ?>
                                    <div class="mt-2 text-sm text-gray-600">
                                        <h4 class="font-bold"><?= html_safe($t['product_details'] ?? 'تفاصيل المنتج'); ?>:</h4>
                                        <ul class="list-disc list-inside space-y-1">
                                            <?php foreach ($item['properties'] as $prop): ?>
                                                <li><span class="font-semibold"><?= html_safe($prop['property_name']); ?>:</span> <?= html_safe($prop['property_value']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-col items-end min-w-[120px]">
                                <?php if ($item['discount_price']): ?>
                                    <p class="text-xs text-red-500 line-through"><?= number_format($item['original_price'], 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></p>
                                    <span class="text-lg font-bold text-green-600"><?= number_format($item['discount_price'], 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                                    <p class="text-sm text-gray-500">(<?= html_safe($t['discount'] ?? 'خصم'); ?> <?= round((($item['original_price'] - $item['discount_price']) / $item['original_price']) * 100); ?>%)</p>
                                <?php else: ?>
                                    <span class="text-lg font-bold text-blue-600"><?= number_format($item['unit_price'], 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                                <?php endif; ?>
                                <div class="flex items-center space-x-2 space-x-reverse my-2">
                                    <button type="button" onclick="updateQuantity(<?= $item['product_id']; ?>, -1, '<?= $order_id_display; ?>')" class="w-8 h-8 rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400">-</button>
                                    <input type="number" name="quantity_<?= $item['product_id']; ?>" id="quantity_<?= $item['product_id']; ?>" value="<?= $item['quantity']; ?>" min="1" class="w-16 text-center border rounded-md py-1" data-product-id="<?= $item['product_id']; ?>" oninput="handleQuantityChange(<?= $item['product_id']; ?>, '<?= $order_id_display; ?>')">
                                    <button type="button" onclick="updateQuantity(<?= $item['product_id']; ?>, 1, '<?= $order_id_display; ?>')" class="w-8 h-8 rounded-full bg-gray-200 text-gray-700 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-400">+</button>
                                </div>
                                <span class="text-lg font-bold text-blue-600" id="total_price_<?= $item['product_id']; ?>"><?= number_format($item['total_price'], 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                                <form action="shopping_cart.php" method="post">
                                    <input type="hidden" name="remove_item" value="1">
                                    <input type="hidden" name="product_id" value="<?= $item['product_id']; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-sm mt-2">
                                        <i class="fas fa-trash-alt"></i> <?= html_safe($t['remove'] ?? 'إزالة'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-md h-fit">
                    <h2 class="text-xl font-bold text-gray-900 mb-4 border-b pb-2"><?= html_safe($t['order_summary'] ?? 'ملخص الطلب'); ?></h2>
                    <form action="checkout_logic.php" method="post" id="checkout_form">
                        <input type="hidden" name="checkout_submit" value="1">
                        <input type="hidden" name="order_id" value="<?= html_safe($order_id_display); ?>">
                        <div class="mb-2 text-sm text-gray-600">
                            <span><strong><?= html_safe($t['order_number'] ?? 'رقم الطلب'); ?>:</strong> #<?= html_safe($order_id_display); ?></span>
                        </div>
                        <div class="mb-2 text-sm text-gray-600">
                            <span><strong><?= html_safe($t['number_of_items'] ?? 'عدد الأصناف'); ?>:</strong> <span id="distinct_items_count"><?= $number_of_distinct_items; ?></span></span>
                        </div>
                        
                        <?php if ($available_points > 0): ?>
                            <div class="mb-4 p-3 bg-blue-50 rounded-md">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-gray-700"><?= html_safe($t['available_points'] ?? 'النقاط المتاحة'); ?>:</span>
                                    <span class="font-bold"><?= number_format($available_points, 0); ?> <?= html_safe($t['points'] ?? 'نقطة'); ?></span>
                                </div>
                                <?php if ($available_points >= 500): ?>
                                    <div class="mt-2">
                                        <label for="points_to_use" class="block text-sm font-medium text-gray-700"><?= html_safe($t['points_to_use'] ?? 'النقاط المستخدمة'); ?>:</label>
                                        <input type="number" id="points_to_use" name="points_to_use" value="0" min="0" max="<?= floor($available_points); ?>" step="500" class="mt-1 block w-full p-2 border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" oninput="updateTotals()">
                                        <p class="text-sm text-gray-500 mt-1"><?= html_safe($t['points_info'] ?? 'كل 500 نقطة = 5 درهم خصم'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= html_safe($t['delivery_method'] ?? 'طريقة التوصيل'); ?>:</label>
                            <div class="flex flex-wrap gap-4">
                                <div class="flex items-center">
                                    <input type="radio" id="pickup_option" name="delivery_type" value="pickup" class="h-4 w-4 text-blue-600 border-gray-300" onchange="toggleDeliveryOptions()" <?= $delivery_type_prefill === 'pickup' ? 'checked' : ''; ?>>
                                    <label for="pickup_option" class="ml-2 mr-2 text-sm text-gray-700"><?= html_safe($t['pickup_from_store'] ?? 'الاستلام من المتجر'); ?></label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="vendor_delivery_option" name="delivery_type" value="vendor_delivery" class="h-4 w-4 text-blue-600 border-gray-300" onchange="toggleDeliveryOptions()" <?= $delivery_type_prefill === 'vendor_delivery' ? 'checked' : ''; ?>>
                                    <label for="vendor_delivery_option" class="ml-2 mr-2 text-sm text-gray-700"><?= html_safe($t['vendor_delivery'] ?? 'توصيل البائع'); ?></label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="third_party_option" name="delivery_type" value="third_party" class="h-4 w-4 text-blue-600 border-gray-300" onchange="toggleDeliveryOptions()" <?= $delivery_type_prefill === 'third_party' ? 'checked' : ''; ?>>
                                    <label for="third_party_option" class="ml-2 mr-2 text-sm text-gray-700"><?= html_safe($t['third_party_delivery'] ?? 'توصيل طرف ثالث'); ?></label>
                                </div>
                            </div>
                        </div>

                        <div id="delivery_details_section" class="space-y-4">
                            <div class="mb-4">
                                <label for="to_country_id" class="block text-sm font-medium text-gray-700"><?= html_safe($t['country'] ?? 'الدولة'); ?>:</label>
                                <select id="to_country_id" name="to_country_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md" onchange="fetchCities()">
                                    <option value=""><?= html_safe($t['select_country'] ?? 'اختر الدولة'); ?></option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?= html_safe($country['id']); ?>" <?= $country['id'] == $to_country_prefill ? 'selected' : ''; ?>>
                                            <?= html_safe($country['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label for="to_city_id" class="block text-sm font-medium text-gray-700"><?= html_safe($t['city'] ?? 'المدينة'); ?>:</label>
                                <select id="to_city_id" name="to_city_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md" onchange="updateShippingCompanies(); updateTotals()">
                                    <option value=""><?= html_safe($t['select_city'] ?? 'اختر المدينة'); ?></option>
                                </select>
                            </div>
                            <div id="shipping_company_section" class="mb-4">
                                <label for="shipping_company_id" class="block text-sm font-medium text-gray-700 mb-1"><?= html_safe($t['select_shipping_company'] ?? 'اختر شركة الشحن'); ?>:</label>
                                <select id="shipping_company_id" name="shipping_company_id" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md" onchange="updateTotals()">
                                    <option value=""><?= html_safe($t['select_shipping_company'] ?? 'اختر شركة الشحن'); ?></option>
                                    <?php foreach ($third_party_services as $company): ?>
                                        <option value="<?= html_safe($company['id']); ?>" data-price="<?= $company['price']; ?>" <?= $company['id'] == $shipping_company_prefill ? 'selected' : ''; ?>>
                                            <?= html_safe($company['company_name']); ?> (<?= $company['price'] ? number_format($company['price'], 2) . ' ' . html_safe($t['currency'] ?? 'درهم') : 'غير متوفر'; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label for="delivery_address" class="block text-sm font-medium text-gray-700"><?= html_safe($t['delivery_address'] ?? 'عنوان التوصيل'); ?>:</label>
                                <textarea id="delivery_address" name="delivery_address" rows="3" class="mt-1 block w-full p-2 border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="<?= html_safe($t['enter_delivery_address'] ?? 'أدخل عنوان التوصيل'); ?>"><?= html_safe($delivery_address); ?></textarea>
                            </div>
                            <div class="mb-4">
                                <label for="lat" class="block text-sm font-medium text-gray-700">Latitude:</label>
                                <input type="text" id="lat" name="lat" value="<?= html_safe($delivery_lat); ?>" class="mt-1 block w-full p-2 border-gray-300 rounded-md shadow-sm" placeholder="Latitude">
                            </div>
                            <div class="mb-4">
                                <label for="lng" class="block text-sm font-medium text-gray-700">Longitude:</label>
                                <input type="text" id="lng" name="lng" value="<?= html_safe($delivery_lng); ?>" class="mt-1 block w-full p-2 border-gray-300 rounded-md shadow-sm" placeholder="Longitude">
                            </div>
                            <div class="mb-4">
                                <button type="button" id="get_location_btn" class="w-full bg-gray-500 text-white py-2 rounded-md font-semibold text-center hover:bg-gray-600 transition duration-300">
                                    <i class="fas fa-location-arrow"></i> <?= html_safe($t['get_current_location'] ?? 'تحديد موقعي الحالي'); ?>
                                </button>
                            </div>
                            <div class="mb-4">
                                <div id="map" class="rounded-md shadow-md"></div>
                            </div>
                        </div>

                        <div class="space-y-2 text-sm text-gray-600 mb-4">
                            <div class="flex justify-between">
                                <span><?= html_safe($t['products_total'] ?? 'إجمالي المنتجات'); ?></span>
                                <span class="font-bold" id="items_total"><?= number_format($total_cost, 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span><?= html_safe($t['tax'] ?? 'الضريبة'); ?></span>
                                <span class="font-bold" id="total_tax"><?= number_format($total_tax, 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span><?= html_safe($t['shipping_fee'] ?? 'رسوم الشحن'); ?></span>
                                <span id="shipping-fee" class="font-bold"><?= number_format($shipping_fee, 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span><?= html_safe($t['points_discount'] ?? 'خصم النقاط'); ?></span>
                                <span id="points_discount" class="font-bold">0.00 <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                            </div>
                        </div>
                        <hr class="my-4">
                        <div class="flex justify-between items-center text-lg font-bold text-gray-900">
                            <span><?= html_safe($t['grand_total'] ?? 'الإجمالي الكلي'); ?></span>
                            <span id="order-total" class="text-blue-600"><?= number_format($order_total, 2); ?> <?= html_safe($t['currency'] ?? 'درهم'); ?></span>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1"><?= html_safe($t['payment_method'] ?? 'طريقة الدفع'); ?>:</label>
                            <div class="flex flex-wrap gap-4">
                                <div class="flex items-center">
                                    <input type="radio" id="payment_cod" name="payment_method" value="cod" class="h-4 w-4 text-blue-600 border-gray-300" checked>
                                    <label for="payment_cod" class="ml-2 mr-2 text-sm text-gray-700"><?= html_safe($t['cash_on_delivery'] ?? 'الدفع عند الاستلام'); ?></label>
                                </div>
                                <?php if ($has_payment_gateway): ?>
                                    <div class="flex items-center">
                                        <input type="radio" id="payment_card" name="payment_method" value="card" class="h-4 w-4 text-blue-600 border-gray-300">
                                        <label for="payment_card" class="ml-2 mr-2 text-sm text-gray-700"><?= html_safe($t['credit_card'] ?? 'بطاقة ائتمانية'); ?></label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-green-600 text-white py-3 rounded-lg font-bold text-center block mt-6 hover:bg-green-700 transition duration-300" <?= !isset($_SESSION['user_id']) || empty($cart_items) ? 'disabled' : ''; ?>>
                            <i class="fas fa-credit-card <?= html_safe($dir) === 'rtl' ? 'ml-2' : 'mr-2'; ?>"></i> <?= html_safe($t['checkout'] ?? 'إتمام الشراء'); ?>
                        </button>
                    </form>
                    <a href="index.php" class="w-full text-center block mt-4 text-sm text-blue-600 font-semibold hover:underline">
                        <?= html_safe($t['continue_shopping'] ?? 'العودة للتسوق'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <footer class="bg-gray-800 text-white py-6 mt-8">
        <div class="container mx-auto px-4 text-center">
            <p class="text-sm text-gray-400">© 2025 <?= html_safe($t['platform_name'] ?? 'easyoffer24'); ?>. All rights reserved.</p>
        </div>
    </footer>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script>
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const closeMenuButton = document.getElementById('close-menu-button');
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.remove('<?= html_safe($dir) === 'rtl' ? 'translate-x-full' : '-translate-x-full'; ?>');
            mobileMenu.classList.add('translate-x-0');
        });
        closeMenuButton.addEventListener('click', () => {
            mobileMenu.classList.add('<?= html_safe($dir) === 'rtl' ? 'translate-x-full' : '-translate-x-full'; ?>');
            mobileMenu.classList.remove('translate-x-0');
        });

        const currentOrderId = '<?= html_safe($order_id_display); ?>';
        const defaultCountryId = '<?= html_safe($user_default_country_id); ?>';
        const defaultCityId = '<?= html_safe($user_default_city_id); ?>';
        const deliveryTypePrefill = '<?= html_safe($delivery_type_prefill); ?>';
        const toCountryPrefill = '<?= html_safe($to_country_prefill); ?>';
        const toCityPrefill = '<?= html_safe($to_city_prefill); ?>';
        const deliveryLatPrefill = '<?= html_safe($delivery_lat); ?>' || '25.276987';
        const deliveryLngPrefill = '<?= html_safe($delivery_lng); ?>' || '55.296249';

        async function updateQuantity(productId, change, orderId) {
            const quantityInput = document.getElementById(`quantity_${productId}`);
            let newQuantity = parseInt(quantityInput.value) + change;
            if (newQuantity < 1 || isNaN(newQuantity)) {
                newQuantity = 1;
            }
            quantityInput.value = newQuantity;
            
            await handleQuantityChange(productId, orderId);
        }

        async function handleQuantityChange(productId, orderId) {
            const quantityInput = document.getElementById(`quantity_${productId}`);
            let newQuantity = parseInt(quantityInput.value) || 1;
            
            if (newQuantity < 1) {
                newQuantity = 1;
                quantityInput.value = 1;
            }
            
            const formData = new FormData();
            formData.append('action', 'update_quantity_ajax');
            formData.append('order_id', orderId);
            formData.append('product_id', productId);
            formData.append('quantity', newQuantity);
            formData.append('delivery_type', document.querySelector('input[name="delivery_type"]:checked')?.value || 'pickup');
            formData.append('shipping_company_id', document.getElementById('shipping_company_id')?.value || '');
            formData.append('to_country_id', document.getElementById('to_country_id')?.value || '');
            formData.append('to_city_id', document.getElementById('to_city_id')?.value || '');
            formData.append('lat', document.getElementById('lat').value || '');
            formData.append('lng', document.getElementById('lng').value || '');
            formData.append('points_to_use', document.getElementById('points_to_use')?.value || 0);
            
            try {
                const response = await fetch('shopping_cart.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    document.getElementById(`total_price_${productId}`).textContent = `${parseFloat(result.total_price).toFixed(2)} <?= html_safe($t['currency'] ?? 'درهم'); ?>`;
                    document.getElementById('cart_count').textContent = result.total_items_count;
                    document.getElementById('distinct_items_count').textContent = result.distinct_items_count;
                    updateTotalsFromAjax(result);
                } else {
                    console.error('Failed to update quantity:', result.message);
                }
            } catch (error) {
                console.error('Error during quantity update:', error);
            }
        }

        async function updateTotals() {
            const orderId = '<?= html_safe($order_id_display); ?>';
            if (!orderId) return;

            const deliveryType = document.querySelector('input[name="delivery_type"]:checked')?.value || 'pickup';
            const shippingCompanyId = document.getElementById('shipping_company_id')?.value || '';
            const toCountryId = document.getElementById('to_country_id')?.value || '';
            const toCityId = document.getElementById('to_city_id')?.value || '';
            const lat = document.getElementById('lat').value || '';
            const lng = document.getElementById('lng').value || '';
            const pointsToUse = document.getElementById('points_to_use')?.value || 0;

            const formData = new FormData();
            formData.append('action', 'recalculate_totals');
            formData.append('order_id', orderId);
            formData.append('delivery_type', deliveryType);
            formData.append('shipping_company_id', shippingCompanyId);
            formData.append('to_country_id', toCountryId);
            formData.append('to_city_id', toCityId);
            formData.append('lat', lat);
            formData.append('lng', lng);
            formData.append('points_to_use', pointsToUse);

            try {
                const response = await fetch('shopping_cart.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    updateTotalsFromAjax(result);
                } else {
                    console.error('Failed to update totals:', result.message);
                }
            } catch (error) {
                console.error('Error during totals recalculation:', error);
            }
        }

        function updateTotalsFromAjax(data) {
            const currency = '<?= html_safe($t['currency'] ?? 'درهم'); ?>';
            document.getElementById('items_total').textContent = `${parseFloat(data.subtotal).toFixed(2)} ${currency}`;
            document.getElementById('total_tax').textContent = `${parseFloat(data.tax).toFixed(2)} ${currency}`;
            document.getElementById('shipping-fee').textContent = `${parseFloat(data.shipping).toFixed(2)} ${currency}`;
            document.getElementById('points_discount').textContent = `${parseFloat(data.points_discount).toFixed(2)} ${currency}`;
            document.getElementById('order-total').textContent = `${parseFloat(data.total).toFixed(2)} ${currency}`;
        }

        async function fetchCities() {
            const countryId = document.getElementById('to_country_id').value;
            const citySelect = document.getElementById('to_city_id');
            citySelect.innerHTML = `<option value=""><?= html_safe($t['select_city'] ?? 'اختر المدينة'); ?></option>`;

            if (countryId) {
                const formData = new FormData();
                formData.append('action', 'fetch_cities');
                formData.append('country_id', countryId);

                try {
                    const response = await fetch('shopping_cart.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success) {
                        result.cities.forEach(city => {
                            const option = document.createElement('option');
                            option.value = city.id;
                            option.textContent = city.name;
                            if (city.id == toCityPrefill) {
                                option.selected = true;
                            }
                            citySelect.appendChild(option);
                        });
                        updateShippingCompanies();
                        updateTotals();
                    } else {
                        console.error('Failed to fetch cities:', result.message);
                    }
                } catch (error) {
                    console.error('Error fetching cities:', error);
                }
            } else {
                updateShippingCompanies();
                updateTotals();
            }
        }

        async function updateShippingCompanies() {
            const toCountryId = document.getElementById('to_country_id').value;
            const toCityId = document.getElementById('to_city_id').value;
            const shippingCompanySelect = document.getElementById('shipping_company_id');
            
            shippingCompanySelect.innerHTML = `<option value=""><?= html_safe($t['select_shipping_company'] ?? 'اختر شركة الشحن'); ?></option>`;

            if (toCountryId && toCityId) {
                const formData = new FormData();
                formData.append('action', 'fetch_shipping_companies');
                formData.append('to_country_id', toCountryId);
                formData.append('to_city_id', toCityId);

                try {
                    const response = await fetch('shopping_cart.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    console.log('Shipping companies response:', result);
                    if (result.success && result.companies.length > 0) {
                        result.companies.forEach(company => {
                            const option = document.createElement('option');
                            option.value = company.id;
                            option.textContent = `${company.company_name} (${company.price ? company.price.toFixed(2) + ' <?= html_safe($t['currency'] ?? 'درهم'); ?>' : 'غير متوفر'})`;
                            option.dataset.price = company.price || 0;
                            if (company.id == '<?= html_safe($shipping_company_prefill); ?>') {
                                option.selected = true;
                            }
                            shippingCompanySelect.appendChild(option);
                        });
                    } else {
                        console.error('No shipping companies available:', result.message);
                    }
                    updateTotals();
                } catch (error) {
                    console.error('Error fetching shipping companies:', error);
                }
            }
        }

        function toggleDeliveryOptions() {
            const deliveryType = document.querySelector('input[name="delivery_type"]:checked').value;
            const deliverySection = document.getElementById('delivery_details_section');
            const shippingCompanySection = document.getElementById('shipping_company_section');
            
            deliverySection.style.display = 'block';
            shippingCompanySection.style.display = 'block';

            if (deliveryType === 'pickup') {
                deliverySection.style.display = 'none';
                shippingCompanySection.style.display = 'none';
            } else if (deliveryType === 'vendor_delivery') {
                shippingCompanySection.style.display = 'none';
            }
            updateTotals();
        }

        document.getElementById('points_to_use')?.addEventListener('input', updateTotals);

        let map = null;
        let marker = null;
        function initMap() {
            const initialLat = parseFloat(deliveryLatPrefill) || 25.276987;
            const initialLng = parseFloat(deliveryLngPrefill) || 55.296249;

            if (map) {
                map.remove();
            }
            map = L.map('map').setView([initialLat, initialLng], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    document.getElementById('lat').value = lat;
                    document.getElementById('lng').value = lng;
                    
                    if (marker) {
                        map.removeLayer(marker);
                    }
                    marker = L.marker([lat, lng]).addTo(map);
                    map.setView([lat, lng], 16);
                    
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`)
                        .then(response => response.json())
                        .then(data => {
                            const area = data.address.neighbourhood || data.address.suburb || data.address.city_district || data.address.city || data.display_name || '';
                            document.getElementById('delivery_address').value = area;
                            updateOrderLocation(currentOrderId, lat, lng, area);
                        })
                        .catch(error => console.error('Error fetching address:', error));
                }, () => {
                    if (initialLat && initialLng) {
                        marker = L.marker([initialLat, initialLng]).addTo(map);
                    }
                });
            } else if (initialLat && initialLng) {
                marker = L.marker([initialLat, initialLng]).addTo(map);
            }

            map.on('click', function(e) {
                const lat = e.latlng.lat;
                const lng = e.latlng.lng;
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                if (marker) {
                    map.removeLayer(marker);
                }
                marker = L.marker(e.latlng).addTo(map);
                
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`)
                    .then(response => response.json())
                    .then(data => {
                        const area = data.address.neighbourhood || data.address.suburb || data.address.city_district || data.address.city || data.display_name || '';
                        document.getElementById('delivery_address').value = area;
                        updateOrderLocation(currentOrderId, lat, lng, area);
                    })
                    .catch(error => console.error('Error fetching address:', error));
            });
        }
        
        document.getElementById('get_location_btn').addEventListener('click', () => {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    document.getElementById('lat').value = lat;
                    document.getElementById('lng').value = lng;
                    
                    if (marker) {
                        map.removeLayer(marker);
                    }
                    marker = L.marker([lat, lng]).addTo(map);
                    map.setView([lat, lng], 16);
                    
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=en`)
                        .then(response => response.json())
                        .then(data => {
                            const area = data.address.neighbourhood || data.address.suburb || data.address.city_district || data.address.city || data.display_name || '';
                            document.getElementById('delivery_address').value = area;
                            updateOrderLocation(currentOrderId, lat, lng, area);
                        })
                        .catch(error => console.error('Error fetching address:', error));
                }, () => {
                    alert("<?= html_safe($t['geolocation_error'] ?? 'تعذر الحصول على موقعك. يرجى التأكد من تمكين خدمات الموقع.'); ?>");
                });
            } else {
                alert("<?= html_safe($t['geolocation_not_supported'] ?? 'متصفحك لا يدعم تحديد الموقع الجغرافي.'); ?>");
            }
        });

        async function updateOrderLocation(orderId, lat, lng, address) {
            const formData = new FormData();
            formData.append('action', 'update_order_location');
            formData.append('order_id', orderId);
            formData.append('lat', lat);
            formData.append('lng', lng);
            formData.append('delivery_address', address);

            try {
                const response = await fetch('shopping_cart.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to update order location:', result.message);
                }
            } catch (error) {
                console.error('Error updating order location:', error);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (toCountryPrefill) {
                document.getElementById('to_country_id').value = toCountryPrefill;
                fetchCities().then(() => {
                    if (toCityPrefill) {
                        document.getElementById('to_city_id').value = toCityPrefill;
                        updateShippingCompanies();
                    }
                });
            }
            toggleDeliveryOptions();
            initMap();
        });
    </script>
</body>
</html