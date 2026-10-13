<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCustomerNotificationSettings extends BookingModel
{
    protected $primaryKey = 'business_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public static function defaults(): array
    {
        return [
            'channels' => [
                'email' => ['enabled' => false, 'credentials' => []],
                'sms' => ['enabled' => false, 'credentials' => []],
                'whatsapp' => ['enabled' => false, 'credentials' => []],
            ],
            'rules' => [
                'booking.created' => ['enabled' => false, 'channels' => ['email', 'whatsapp', 'sms']],
                'booking.reminder' => ['enabled' => false, 'channels' => ['email', 'whatsapp', 'sms'], 'lead_minutes' => 1440],
                'booking.rescheduled' => ['enabled' => false, 'channels' => ['email', 'whatsapp', 'sms']],
                'booking.cancelled' => ['enabled' => false, 'channels' => ['email', 'whatsapp', 'sms']],
            ],
            'templates' => [
                'booking.created' => self::templates('Your booking is confirmed with {business_name}', 'Hello {customer_name}, your booking {booking_reference} for {service_name} is confirmed at {appointment_datetime}.', 'Booking confirmed: {booking_reference} at {appointment_datetime}.'),
                'booking.reminder' => self::templates('Appointment reminder: {booking_reference}', 'Hello {customer_name}, this is a reminder for {service_name} at {appointment_datetime}.', 'Reminder: {service_name} at {appointment_datetime}.'),
                'booking.rescheduled' => self::templates('Your booking has changed: {booking_reference}', 'Hello {customer_name}, your booking is now scheduled for {appointment_datetime}.', 'Rescheduled: {booking_reference} is now {appointment_datetime}.'),
                'booking.cancelled' => self::templates('Booking cancelled: {booking_reference}', 'Hello {customer_name}, your booking for {service_name} at {appointment_datetime} has been cancelled.', 'Cancelled: {booking_reference} at {appointment_datetime}.'),
            ],
        ];
    }

    private static function templates(string $subject, string $email, string $message): array
    {
        return [
            'email' => ['enabled' => true, 'subject' => $subject, 'body' => $email],
            'sms' => ['enabled' => true, 'subject' => '', 'body' => $message],
            'whatsapp' => ['enabled' => true, 'subject' => '', 'body' => $message, 'content_sid' => ''],
        ];
    }
}
