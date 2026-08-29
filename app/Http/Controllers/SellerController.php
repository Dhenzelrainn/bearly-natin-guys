<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SellerController extends Controller
{
    public function dashboard(): View
    {
        return view('seller.dashboard', [
            'seller' => [
                'name' => 'Bea Rivera',
                'first_name' => 'Bea',
                'initials' => 'BR',
                'email' => 'bea@juansclothing.test',
                'store' => "Juan's Clothing Shop",
            ],
            'notifications' => [
                ['title' => '8 new orders need review', 'time' => '5 minutes ago', 'type' => 'warning'],
                ['title' => 'Classic Linen Shirt is low in stock', 'time' => '42 minutes ago', 'type' => 'danger'],
                ['title' => 'Order #BR-1047 is ready for pickup', 'time' => '1 hour ago', 'type' => 'success'],
            ],
            'stats' => [
                ['label' => 'Total Sales', 'value' => '₱128,450', 'change' => '+12.5% this month', 'icon' => 'philippine-peso', 'tone' => 'gold'],
                ['label' => 'Total Orders', 'value' => '342', 'change' => '+18 this week', 'icon' => 'shopping-bag', 'tone' => 'olive'],
                ['label' => 'Active Products', 'value' => '24', 'change' => '3 added this month', 'icon' => 'package', 'tone' => 'brown'],
                ['label' => 'Low Stock', 'value' => '5', 'change' => 'Needs attention', 'icon' => 'circle-alert', 'tone' => 'red'],
            ],
            'sales' => [9800, 13400, 13800, 18100, 22800, 15700, 24100],
            'orderStatuses' => [
                ['label' => 'New Orders', 'count' => 8, 'percent' => 40, 'icon' => 'shopping-bag', 'tone' => 'amber'],
                ['label' => 'To Prepare', 'count' => 5, 'percent' => 32, 'icon' => 'package-open', 'tone' => 'orange'],
                ['label' => 'Ready for Pickup', 'count' => 3, 'percent' => 22, 'icon' => 'store', 'tone' => 'olive'],
                ['label' => 'In Transit', 'count' => 7, 'percent' => 55, 'icon' => 'truck', 'tone' => 'blue'],
            ],
            'recentOrders' => [
                ['id' => '#BR-1048', 'customer' => 'Maria Santos', 'items' => '2 items', 'total' => '₱1,850', 'status' => 'To Prepare', 'tone' => 'warning'],
                ['id' => '#BR-1047', 'customer' => 'Carlo Reyes', 'items' => '1 item', 'total' => '₱899', 'status' => 'Ready for Pickup', 'tone' => 'success'],
                ['id' => '#BR-1046', 'customer' => 'Anne Cruz', 'items' => '3 items', 'total' => '₱2,450', 'status' => 'In Transit', 'tone' => 'info'],
                ['id' => '#BR-1045', 'customer' => 'Miguel Tan', 'items' => '1 item', 'total' => '₱1,299', 'status' => 'Completed', 'tone' => 'neutral'],
            ],
            'inventoryAlerts' => [
                ['name' => 'Classic Linen Shirt', 'stock' => '3 left', 'icon' => 'shirt', 'status' => 'Low Stock', 'tone' => 'warning'],
                ['name' => 'Canvas Tote Bag', 'stock' => '2 left', 'icon' => 'shopping-bag', 'status' => 'Low Stock', 'tone' => 'warning'],
                ['name' => 'Everyday Sneakers / Size 38', 'stock' => '0 left', 'icon' => 'footprints', 'status' => 'Out of Stock', 'tone' => 'danger'],
            ],
        ]);
    }
}
