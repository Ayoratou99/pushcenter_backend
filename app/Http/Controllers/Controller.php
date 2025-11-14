<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     version="1.0.0",
 *     title="AninfPush API Documentation",
 *     description="API microservice for managing multi-channel messaging (Email, SMS, WhatsApp) with Keycloak authentication",
 *     @OA\Contact(
 *         email="support@aninfpush.com"
 *     ),
 *     @OA\License(
 *         name="Proprietary",
 *         url="https://aninfpush.com/license"
 *     )
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="API Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Enter Keycloak JWT token"
 * )
 *
 * @OA\Schema(
 *     schema="Business",
 *     type="object",
 *     title="Business",
 *     description="Business model",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Acme Corporation"),
 *     @OA\Property(property="email", type="string", format="email", example="contact@acme.com"),
 *     @OA\Property(property="phone_number", type="string", example="+237670000000"),
 *     @OA\Property(property="country_code", type="string", example="+237"),
 *     @OA\Property(property="website", type="string", example="https://acme.com"),
 *     @OA\Property(property="description", type="string", example="A leading technology company"),
 *     @OA\Property(property="address", type="string", example="123 Main St"),
 *     @OA\Property(property="city", type="string", example="Douala"),
 *     @OA\Property(property="country", type="string", example="Cameroon"),
 *     @OA\Property(property="timezone", type="string", example="Africa/Douala"),
 *     @OA\Property(property="status", type="string", enum={"active", "inactive", "suspended"}, example="active"),
 *     @OA\Property(property="verification_status", type="string", enum={"pending", "verified", "rejected"}, example="verified"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-01-01T00:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-01-01T00:00:00Z")
 * )
 *
 * @OA\Schema(
 *     schema="Message",
 *     type="object",
 *     title="Message",
 *     description="Message model",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="business_id", type="integer", example=1),
 *     @OA\Property(property="message_type", type="string", enum={"email", "sms", "whatsapp"}, example="email"),
 *     @OA\Property(property="status", type="string", enum={"pending", "queued", "sending", "sent", "delivered", "read", "failed", "cancelled"}, example="delivered"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-01-01T00:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-01-01T00:00:00Z"),
 *     @OA\Property(property="business", ref="#/components/schemas/Business")
 * )
 *
 * @OA\Schema(
 *     schema="SmtpSetting",
 *     type="object",
 *     title="SMTP Setting",
 *     description="SMTP Setting model",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="business_id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Primary SMTP"),
 *     @OA\Property(property="description", type="string", example="Main email configuration"),
 *     @OA\Property(property="host", type="string", example="smtp.gmail.com"),
 *     @OA\Property(property="port", type="integer", example=587),
 *     @OA\Property(property="encryption", type="string", enum={"tls", "ssl", "none"}, example="tls"),
 *     @OA\Property(property="username", type="string", example="user@example.com"),
 *     @OA\Property(property="from_email", type="string", example="sender@example.com"),
 *     @OA\Property(property="from_name", type="string", example="My Company"),
 *     @OA\Property(property="reply_to_email", type="string", nullable=true, example="reply@example.com"),
 *     @OA\Property(property="reply_to_name", type="string", nullable=true, example="Support"),
 *     @OA\Property(property="timeout", type="integer", example=30),
 *     @OA\Property(property="verify_peer", type="boolean", example=true),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="is_default", type="boolean", example=false),
 *     @OA\Property(property="test_status", type="string", enum={"not_tested", "success", "failed"}, example="success"),
 *     @OA\Property(property="messages_sent", type="integer", example=150),
 *     @OA\Property(property="second_limit", type="integer", nullable=true, example=10),
 *     @OA\Property(property="hourly_limit", type="integer", nullable=true, example=1000),
 *     @OA\Property(property="daily_limit", type="integer", nullable=true, example=10000),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-01-01T00:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-01-01T00:00:00Z")
 * )
 */
abstract class Controller
{
    //
}
