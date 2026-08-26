<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your Outdoor Fix admin account is ready</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:'Helvetica Neue', Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e5e5;">

        {{-- Header --}}
        <tr>
          <td style="background-color:#0a0a0a; padding:28px 32px;">
            <span style="display:inline-block; color:#ffffff; font-size:13px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
              Outdoor <span style="color:#dc2626;">Fix</span>
            </span>
          </td>
        </tr>

        {{-- Body --}}
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 4px; font-size:12px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#dc2626;">
              Admin account created
            </p>
            <h1 style="margin:0 0 16px; font-size:22px; font-weight:800; color:#0a0a0a;">
              Welcome, {{ $name }}
            </h1>
            <p style="margin:0 0 24px; font-size:14px; line-height:1.6; color:#525252;">
              The Outdoor Fix super admin has set up an admin account for you. Use the credentials
              below to sign in, and change your password once you're in.
            </p>

            {{-- Credentials box --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fafafa; border:1px solid #e5e5e5; border-radius:8px; margin-bottom:24px;">
              <tr>
                <td style="padding:16px 20px;">
                  <p style="margin:0 0 2px; font-size:11px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; color:#a3a3a3;">Email</p>
                  <p style="margin:0 0 14px; font-size:14px; font-weight:600; color:#0a0a0a; font-family:'Courier New', Courier, monospace;">{{ $email }}</p>
                  <p style="margin:0 0 2px; font-size:11px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; color:#a3a3a3;">Temporary password</p>
                  <p style="margin:0; font-size:14px; font-weight:600; color:#0a0a0a; font-family:'Courier New', Courier, monospace;">{{ $password }}</p>
                </td>
              </tr>
            </table>

            {{-- CTA --}}
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
              <tr>
                <td style="border-radius:6px; background-color:#dc2626;">
                  <a href="{{ $loginUrl }}" style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">
                    Sign in to your account →
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:24px 0 0; font-size:12px; line-height:1.6; color:#a3a3a3;">
              If you weren't expecting this account, you can ignore this email.
            </p>
          </td>
        </tr>

        {{-- Footer --}}
        <tr>
          <td style="padding:20px 32px; border-top:1px solid #f0f0f0;">
            <p style="margin:0; font-size:11px; color:#a3a3a3;">Outdoor Fix · Equipment lease-to-own</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
