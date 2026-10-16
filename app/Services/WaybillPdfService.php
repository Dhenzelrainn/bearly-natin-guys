<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Waybill;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Picqer\Barcode\Renderers\HtmlRenderer;
use Picqer\Barcode\Types\TypeCode128;

class WaybillPdfService
{
    /** @param Collection<int, Waybill> $waybills */
    public function render(Collection $waybills): string
    {
        $logoPath = public_path('images/bearly-logo-waybill.jpg');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;
        $labels = $waybills
            ->map(fn (Waybill $waybill): array => $this->label($waybill, $logoDataUri))
            ->all();

        return Pdf::loadView('seller.fulfillment.waybill-pdf', ['labels' => $labels])
            ->setPaper([0, 0, 288, 432])
            ->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ])
            ->output();
    }

    private function label(Waybill $waybill, ?string $logoDataUri): array
    {
        $shipment = $waybill->shipment;
        $sellerOrder = $shipment->sellerOrder;
        $order = $sellerOrder->order;
        $store = $sellerOrder->store;
        $origin = $shipment->originAddress;
        $barcodeValue = trim((string) ($waybill->barcode_value ?: $waybill->waybill_no));
        $barcode = (new TypeCode128)->getBarcode($barcodeValue);
        $barcodeHtml = (new HtmlRenderer)->render($barcode, 310, 48);

        return [
            'waybill_no' => (string) $waybill->waybill_no,
            'seller_order_no' => (string) $sellerOrder->seller_order_no,
            'order_no' => (string) $order->order_no,
            'store_name' => (string) $store->name,
            'logo_data_uri' => $logoDataUri,
            'sender_address' => $this->address($origin) ?: 'Seller pickup address on file',
            'sender_phone' => (string) ($origin?->phone ?: $store->contact_phone ?: '—'),
            'recipient_name' => (string) ($order->recipient_name ?: $order->buyer?->name ?: 'Recipient'),
            'recipient_phone' => (string) ($order->recipient_phone ?: '—'),
            'recipient_address' => implode(', ', array_filter([
                $order->address_line,
                $order->barangay,
                $order->city_municipality,
                $order->province,
                $order->postal_code,
            ], fn ($part): bool => filled($part))),
            'piece_count' => max(1, (int) $waybill->piece_count),
            'weight' => number_format((float) $waybill->total_weight_kg, 3),
            'dimensions' => implode(' × ', array_map(
                fn ($value): string => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : '—',
                [$waybill->length_cm, $waybill->width_cm, $waybill->height_cm],
            )),
            'cod_amount' => (int) $shipment->cod_amount_minor > 0
                ? $this->peso((int) $shipment->cod_amount_minor)
                : null,
            'generated_at' => $waybill->generated_at?->format('M j, Y g:i A') ?? now()->format('M j, Y g:i A'),
            'barcode_value' => $barcodeValue,
            'barcode_html' => $barcodeHtml,
        ];
    }

    private function address(?Address $address): string
    {
        if (! $address) {
            return '';
        }

        return implode(', ', array_filter([
            trim(implode(' ', array_filter([$address->house_number, $address->street]))),
            $address->barangay,
            $address->city_municipality,
            $address->province,
            $address->postal_code,
        ], fn ($part): bool => filled($part)));
    }

    private function peso(int $minor): string
    {
        return '₱'.number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
