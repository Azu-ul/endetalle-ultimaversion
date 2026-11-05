<?php

namespace App\Services\Export;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resguardo de usuarios y clientes para abrir en Excel: quién es, cuándo se registró, qué plan tiene,
 * cuándo vence y con qué número de operación pagó (Mercado Pago / PayPal).
 */
class UsersExport
{
    public const USER_HEADERS = [
        'ID', 'Nombre', 'Email', 'Rol', 'Estado de la cuenta', 'Email verificado', 'Fecha de alta',
        'Plan actual', 'Origen del plan', 'Inicio del plan', 'Vence el plan',
        'Medio de pago', 'N° de operación (pago)', 'N° de orden (Mercado Pago / PayPal)', 'Importe pagado', 'Moneda', 'Fecha del pago', 'Orden interna',
    ];

    public const USER_WIDTHS = [7, 28, 34, 14, 12, 11, 17, 14, 14, 17, 17, 14, 22, 28, 13, 8, 17, 38];

    public const PAYMENT_HEADERS = [
        'Fecha del pago', 'Nombre', 'Email', 'Plan', 'Período', 'Medio de pago', 'Importe', 'Moneda', 'Estado',
        'N° de operación (pago)', 'N° de orden (Mercado Pago / PayPal)', 'Orden interna',
    ];

    public const PAYMENT_WIDTHS = [17, 28, 34, 14, 12, 14, 12, 8, 11, 22, 28, 38];

    private const ROLES = ['superadmin' => 'Administrador', 'colaborador' => 'Colaborador', 'usuario' => 'Cliente'];
    private const PROVIDERS = ['mercadopago' => 'Mercado Pago', 'paypal' => 'PayPal', 'manual' => 'Manual (cortesía)'];
    private const PERIODS = ['monthly' => 'Mensual', 'quarterly' => 'Trimestral', 'yearly' => 'Anual'];
    private const ORDER_STATUS = ['paid' => 'Pagado', 'refunded' => 'Reembolsado'];

    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('cms.display_timezone');
    }

    /** @return array<int, array<int, string|int|float|null>> una fila por usuario */
    public function userRows(): array
    {
        // Último pago de cada usuario (pagado o reembolsado)
        $lastOrders = Order::with('plan:id,name')
            ->whereIn('status', ['paid', 'refunded'])
            ->orderByDesc('paid_at')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $rows = [];
        User::with(['roles:id,name', 'subscriptions' => fn ($q) => $q->live()
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with('plan:id,name')
            ->orderByDesc('starts_at')])
            ->orderBy('id')
            ->chunk(500, function (Collection $users) use (&$rows, $lastOrders) {
                foreach ($users as $user) {
                    $sub = $user->subscriptions->first();
                    $order = $lastOrders[$user->id] ?? null;
                    $role = $user->roles->pluck('name')->first();

                    $rows[] = [
                        $user->id,
                        $user->name,
                        $user->email,
                        self::ROLES[$role] ?? ($role ?: 'Cliente'),
                        $user->status === 'active' ? 'Activa' : 'Suspendida',
                        $user->email_verified_at ? 'Sí' : 'No',
                        $this->date($user->created_at),
                        $sub?->plan?->name ?? 'Free',
                        $sub ? ($sub->provider === 'manual' ? 'Manual (cortesía)' : 'Pago') : '',
                        $this->date($sub?->starts_at),
                        $sub ? ($sub->ends_at ? $this->date($sub->ends_at) : 'Sin vencimiento') : '',
                        $order ? (self::PROVIDERS[$order->provider] ?? $order->provider) : ($sub ? (self::PROVIDERS[$sub->provider] ?? $sub->provider) : ''),
                        $order?->provider_payment_id,
                        $order?->provider_order_id,
                        $order ? (float) $order->amount : null,
                        $order?->currency,
                        $this->date($order?->paid_at),
                        $order?->uuid,
                    ];
                }
            });

        return $rows;
    }

    /** @return array<int, array<int, string|int|float|null>> una fila por pago */
    public function paymentRows(): array
    {
        return Order::with(['user:id,name,email', 'plan:id,name'])
            ->whereIn('status', ['paid', 'refunded'])
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (Order $o) => [
                $this->date($o->paid_at),
                $o->user?->name,
                $o->user?->email,
                $o->plan?->name,
                self::PERIODS[$o->period] ?? $o->period,
                self::PROVIDERS[$o->provider] ?? $o->provider,
                (float) $o->amount,
                $o->currency,
                self::ORDER_STATUS[$o->status] ?? $o->status,
                $o->provider_payment_id,
                $o->provider_order_id,
                $o->uuid,
            ])
            ->all();
    }

    public function toXlsx(string $path): void
    {
        (new XlsxWriter())
            ->addSheet('Usuarios', self::USER_HEADERS, $this->userRows(), self::USER_WIDTHS)
            ->addSheet('Pagos', self::PAYMENT_HEADERS, $this->paymentRows(), self::PAYMENT_WIDTHS)
            ->save($path);
    }

    /** CSV con punto y coma y BOM UTF-8: Excel en español lo abre en columnas y con las tildes bien. */
    public function usersCsv(): string
    {
        return $this->csv(self::USER_HEADERS, $this->userRows());
    }

    public function csv(array $headers, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $line = fn (array $cells) => fputcsv($out, array_map(fn ($c) => $this->csvCell($c), $cells), ';', '"', '\\', "\r\n");
        $line($headers);
        foreach ($rows as $row) {
            $line($row);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Un nombre que empieza con = + - @ se tomaría como fórmula al abrirlo en Excel: se neutraliza. */
    private function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text)) {
            return "'" . $text;
        }

        return $text;
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->setTimezone($this->tz)->format('d/m/Y H:i') : null;
    }
}
