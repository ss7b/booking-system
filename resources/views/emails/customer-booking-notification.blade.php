<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @media only screen and (max-width: 600px) {
            .email-card { padding: 24px 18px !important; }
            .email-title { font-size: 22px !important; }
        }
    </style>
</head>
<body dir="rtl" style="margin:0;padding:24px 12px;background-color:#F4F7F8;color:#163344;font-family:Arial,Tahoma,sans-serif;">
    <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#F4F7F8" style="width:100%;background-color:#F4F7F8;">
        <tr>
            <td align="center" style="padding:12px;">
                <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">
                    <tr>
                        <td style="padding:8px 12px 18px;color:#163344;font-size:18px;font-weight:700;text-align:center;">
                            {{ $businessName }}
                        </td>
                    </tr>
                    <tr>
                        <td class="email-card" bgcolor="#ffffff" style="padding:32px 28px;background-color:#ffffff;border:1px solid #E1E9EC;border-radius:12px;">
                            <h1 class="email-title" style="margin:0 0 12px;color:#163344;font-size:24px;line-height:1.45;text-align:right;">
                                {{ $title }}
                            </h1>
                            <p style="margin:0 0 12px;color:#425B68;font-size:16px;line-height:1.8;text-align:right;">
                                {{ $message }}
                            </p>
                            @if ($customMessage)
                                <p style="margin:0 0 22px;color:#425B68;font-size:14px;line-height:1.8;text-align:right;">
                                    {{ $customMessage }}
                                </p>
                            @endif

                            <table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #E1E9EC;border-collapse:separate;border-radius:8px;">
                                @foreach ($details as $detail)
                                    <tr>
                                        <td style="padding:11px 12px;border-bottom:1px solid #E8EEF0;color:#526A76;font-size:14px;line-height:1.6;text-align:right;">
                                            {{ $detail['label'] }}
                                        </td>
                                        <td style="padding:11px 12px;border-bottom:1px solid #E8EEF0;color:#163344;font-size:14px;font-weight:700;line-height:1.6;text-align:right;">
                                            {{ $detail['value'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>

                            @if ($managementUrl)
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:24px auto 0;">
                                    <tr>
                                        <td align="center" bgcolor="#0F766E" style="border-radius:8px;">
                                            <p style="display:inline-block;max-width:100%;margin:0;padding:14px 20px;border:1px solid #0F766E;border-radius:8px;background-color:#0F766E;color:#ffffff;font-family:Arial,Tahoma,sans-serif;font-size:14px;font-weight:700;line-height:1.8;text-align:center;overflow-wrap:anywhere;word-break:break-word;">
                                                {{ $managementUrl }}
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 12px;color:#617782;font-size:12px;line-height:1.8;text-align:center;">
                            شكرًا لثقتكم بنا.<br>
                            {{ $businessName }}<br>
                            هذا إشعار تلقائي متعلق بحجزك، يرجى عدم الرد على هذه الرسالة.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
