<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMTP Test Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }
        .content {
            background: #f9f9f9;
            padding: 30px;
            border: 1px solid #e0e0e0;
            border-top: none;
        }
        .info-box {
            background: white;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-row {
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #555;
            display: inline-block;
            width: 150px;
        }
        .value {
            color: #333;
        }
        .success-badge {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #888;
            font-size: 12px;
            border-top: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>✅ SMTP Configuration Test</h1>
        <p>Your SMTP settings are working correctly!</p>
    </div>
    
    <div class="content">
        <div style="text-align: center;">
            <span class="success-badge">✓ Test Successful</span>
        </div>
        
        <p>This is a test email to verify that your SMTP configuration is set up correctly and working as expected.</p>
        
        <div class="info-box">
            <h3 style="margin-top: 0; color: #667eea;">SMTP Configuration Details</h3>
            
            <div class="info-row">
                <span class="label">Configuration Name:</span>
                <span class="value">{{ $smtpSetting->name }}</span>
            </div>
            
            @if($smtpSetting->description)
            <div class="info-row">
                <span class="label">Description:</span>
                <span class="value">{{ $smtpSetting->description }}</span>
            </div>
            @endif
            
            <div class="info-row">
                <span class="label">SMTP Host:</span>
                <span class="value">{{ $smtpSetting->host }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Port:</span>
                <span class="value">{{ $smtpSetting->port }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Encryption:</span>
                <span class="value">{{ strtoupper($smtpSetting->encryption) }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">From Email:</span>
                <span class="value">{{ $smtpSetting->from_email }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">From Name:</span>
                <span class="value">{{ $smtpSetting->from_name }}</span>
            </div>
            
            <div class="info-row">
                <span class="label">Test Time:</span>
                <span class="value">{{ now()->format('Y-m-d H:i:s T') }}</span>
            </div>
        </div>
        
        <p style="margin-top: 30px;">
            If you received this email, it means your SMTP configuration is properly configured and ready to use for sending emails through your application.
        </p>
        
        <p style="color: #888; font-size: 14px; margin-top: 30px;">
            <strong>Note:</strong> This is an automated test email. Please do not reply to this message.
        </p>
    </div>
    
    <div class="footer">
        <p>Sent from AninfPush Management System</p>
        <p>{{ config('app.name') }} - {{ now()->year }}</p>
    </div>
</body>
</html>

