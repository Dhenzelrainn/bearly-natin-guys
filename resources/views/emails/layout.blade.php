<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('title', 'Bearly Marketplace')</title>
</head>
<body style="margin:0;padding:0;background-color:#f2eee9;color:#35251e;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">
        @yield('preheader')
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f2eee9" style="width:100%;border-collapse:collapse;background-color:#f2eee9;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <!--[if (gte mso 9)|(IE)]>
                <table role="presentation" width="640" align="center" cellspacing="0" cellpadding="0" border="0"><tr><td>
                <![endif]-->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:640px;border-collapse:separate;border-spacing:0;background-color:#ffffff;border:1px solid #e7dfd6;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td height="5" bgcolor="#e9a72f" style="height:5px;background-color:#e9a72f;font-size:1px;line-height:5px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td bgcolor="#fbf7f1" style="padding:20px 24px;background-color:#fbf7f1;border-bottom:1px solid #eee8e2;">
                            @include('emails.partials.header')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 32px 38px;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#fbf8f4" style="padding:22px 32px;background-color:#fbf8f4;border-top:1px solid #eee8e2;">
                            @include('emails.partials.footer')
                        </td>
                    </tr>
                </table>
                <!--[if (gte mso 9)|(IE)]>
                </td></tr></table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
