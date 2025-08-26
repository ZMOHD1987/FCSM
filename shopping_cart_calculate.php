 <?php
// shopping_cart_calculate.php

// Helper function to safely get array values
function get_array_value($array, $key, $default = null) {
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * Updates an item's quantity and total price in the order_items table.
 * @param mysqli $conn The database connection.
 * @param int $orderId The order ID.
 * @param int $productId The product ID to update.
 * @param int $newQuantity The new quantity for the product.
 * @return array The result of the update operation.
 */
function updateOrderItemQuantity($conn, $orderId, $productId, $newQuantity) {
    if ($newQuantity <= 0) {
        // إذا كانت الكمية صفر أو أقل، احذف العنصر
        $stmt = $conn->prepare("DELETE FROM order_items WHERE order_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $orderId, $productId);
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => "Failed to delete item: " . $stmt->error];
        }
        $stmt->close();
        return ['success' => true];
    }

    // جلب سعر الوحدة للمنتج
    $stmt = $conn->prepare("SELECT price FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    $stmt->close();

    if (!$product) {
        return ['success' => false, 'error' => "Product not found."];
    }

    $unitPrice = $product['price'];
    $newTotalPrice = $unitPrice * $newQuantity;

    // تحديث الكمية والسعر الإجمالي في جدول order_items
    $stmt = $conn->prepare("UPDATE order_items SET quantity = ?, total_price = ? WHERE order_id = ? AND product_id = ?");
    $stmt->bind_param("idii", $newQuantity, $newTotalPrice, $orderId, $productId);
    if (!$stmt->execute()) {
        return ['success' => false, 'error' => "Failed to update quantity: " . $stmt->error];
    }
    $stmt->close();

    return ['success' => true, 'new_total_price' => $newTotalPrice];
}

/**
 * Calculates the complete order totals based on the current data in the database.
 * @param mysqli $conn The database connection.
 * @param int $order_id The order ID.
 * @param string $deliveryType The delivery method.
 * @param int|null $shippingCompanyId The ID of the shipping company.
 * @param int|null $toCountryId The destination country ID.
 * @param int|null $toCityId The destination city ID.
 * @param float|null $lat The latitude of the delivery address.
 * @param float|null $lng The longitude of the delivery address.
 * @param int $points_to_use The number of points to use for a discount.
 * @return array An array containing all calculated totals, or an error.
 */
function calculateOrderTotals($conn, $order_id, $deliveryType, $shippingCompanyId = null, $toCountryId = null, $toCityId = null, $lat = null, $lng = null, $points_to_use = 0) {
    $results = [
        'subtotal' => 0.0,
        'tax' => 0.0,
        'shipping' => 0.0,
        'points_discount' => 0.0,
        'total' => 0.0,
    ];

    // 1. حساب الإجمالي الفرعي والضريبة
    $stmt = $conn->prepare("
        SELECT
            oi.total_price AS item_total_price,
            p.tax_rate
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    if (!$stmt) {
        error_log("Prepare failed for order_items query: " . $conn->error);
        return ['error' => 'Prepare failed: ' . $conn->error];
    }
    $stmt->bind_param("i", $order_id);
    if (!$stmt->execute()) {
        error_log("Execute failed for order_items query: " . $stmt->error);
        $stmt->close();
        return ['error' => 'Execute failed: ' . $stmt->error];
    }
    $items_result = $stmt->get_result();

    $subtotal = 0.0;
    $tax = 0.0;
    while ($item = $items_result->fetch_assoc()) {
        $item_total = get_array_value($item, 'item_total_price', 0.0);
        $tax_rate = get_array_value($item, 'tax_rate', 0.0);
        $subtotal += $item_total;
        $tax += $item_total * ($tax_rate / 100);
    }
    $stmt->close();

    $results['subtotal'] = $subtotal;
    $results['tax'] = $tax;

    // 2. حساب رسوم الشحن (بقية الكود الخاص بك)
    $shipping_fee = 0.0;
    if ($deliveryType === 'pickup') {
        $shipping_fee = 0.0;
    } else if ($deliveryType === 'vendor_delivery') {
        $stmt_vendor = $conn->prepare("SELECT vendor_id FROM orders WHERE id = ?");
        $stmt_vendor->bind_param("i", $order_id);
        $stmt_vendor->execute();
        $vendor_id = $stmt_vendor->get_result()->fetch_row()[0] ?? null;
        $stmt_vendor->close();
        
        if ($vendor_id && $toCountryId && $toCityId) {
            $stmt = $conn->prepare("
                SELECT price, free_shipping_threshold, shipping_type
                FROM vendor_shipping
                WHERE vendor_id = ? AND country_id = ? AND city_id = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->bind_param("iii", $vendor_id, $toCountryId, $toCityId);
            $stmt->execute();
            $vendor_shipping = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($vendor_shipping) {
                $shipping_price = (float)$vendor_shipping['price'];
                $free_threshold = (float)$vendor_shipping['free_shipping_threshold'];
                $shipping_type = $vendor_shipping['shipping_type'];
                
                if ($shipping_type === 'free') {
                    $shipping_fee = 0.0;
                } elseif ($shipping_type === 'paid') {
                    if ($subtotal >= $free_threshold) {
                        $shipping_fee = 0.0;
                    } else {
                        $shipping_fee = $shipping_price;
                    }
                }
            }
        }
    } else if ($deliveryType === 'third_party' && $shippingCompanyId) {
        $stmt = $conn->prepare("
            SELECT price FROM third_party_shipping_prices
            WHERE service_id = ? AND to_country_id = ? AND to_city_id = ?
        ");
        $stmt->bind_param("iii", $shippingCompanyId, $toCountryId, $toCityId);
        $stmt->execute();
        $third_party_price = $stmt->get_result()->fetch_row()[0] ?? null;
        $stmt->close();
        
        if ($third_party_price !== null) {
            $shipping_fee = (float)$third_party_price;
        }
    }
    
    $results['shipping'] = $shipping_fee;

    // 3. حساب خصم النقاط (بقية الكود الخاص بك)
    $points_discount = 0.0;
    if ($points_to_use >= 500) {
        $stmt_vendor = $conn->prepare("SELECT vendor_id FROM orders WHERE id = ?");
        $stmt_vendor->bind_param("i", $order_id);
        $stmt_vendor->execute();
        $vendor_id = $stmt_vendor->get_result()->fetch_row()[0] ?? null;
        $stmt_vendor->close();
        
        if ($vendor_id) {
            $stmt = $conn->prepare("SELECT points_balance FROM customer_vendor_points WHERE user_id = (SELECT user_id FROM orders WHERE id = ?) AND vendor_id = ?");
            $stmt->bind_param("ii", $order_id, $vendor_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $available_points = $result['points_balance'] ?? 0;
            $stmt->close();
            
            if ($points_to_use <= $available_points) {
                $points_discount = $points_to_use * 0.01;
            }
        }
    }

    $results['points_discount'] = $points_discount;

    // 4. حساب الإجمالي الكلي
    $results['total'] = max(0, $subtotal + $tax + $shipping_fee - $points_discount);

    // تحديث قيم الضريبة والشحن في جدول orders
    $stmt_update_order = $conn->prepare("UPDATE orders SET total_amount = ?, tax_amount = ?, shipping_fee = ? WHERE id = ?");
    $stmt_update_order->bind_param("dddi", $results['total'], $results['tax'], $results['shipping'], $order_id);
    if (!$stmt_update_order->execute()) {
        error_log("Failed to update order totals: " . $stmt_update_order->error);
    }
    $stmt_update_order->close();

    error_log("Order totals calculated and updated: order_id=$order_id, subtotal=$subtotal, tax=$tax, shipping=$shipping_fee, points_discount=$points_discount, total={$results['total']}");
    
    return $results;
