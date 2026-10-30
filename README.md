# PNC Logos landing pages

Three responsive landing pages, built from the designs in `final.zip`. Each page works on desktop, tablet and mobile, so the separate `-Mobile` versions aren't needed.

| File | Page |
| --- | --- |
| `Main.html` | Main landing page (leads) |
| `Ecommerce.html` | Ecommerce landing page (online stores) |
| `Traffic.html` | Traffic landing page (get found) |
| `quiz-submit.php` | Emails the 30-second quiz result to the admin and the client |
| `assets/images/logos/` | All images used by the pages |

## Structure

- Each page has a single `<style>` block in the `<head>`. There are no inline `style=""` attributes.
- Each page has a single plain-JavaScript `<script>` at the end of the `<body>`. It has no dependencies.
- Images use relative paths such as `assets/images/logos/01-pnc-logos.gif`.
- `14-pnc-logos-white.png` is the white footer logo. It came from the original desktop page; it wasn't in the zip.

## Quiz emails (setup)

The quiz flow is: your details (name, email, phone, website) → 5 questions → **Submit** → result.

On submit, the page sends the data to `quiz-submit.php`, which sends two emails:

1. **Admin email:** contact details, answers and result. Reply-To is set to the client.
2. **Client email:** their result, what we'll check on the call, a booking button and a copy of their answers.

To use it:

1. Host the files on a server with PHP 7.1+ and a working `mail()` function. cPanel, Plesk and Hostinger all work. Static hosts such as GitHub Pages or Netlify can't run PHP.
2. Edit the settings at the top of `quiz-submit.php`:
   - `ADMIN_EMAIL`: where new leads go (default `solutions@pnclogos.com`).
   - `FROM_EMAIL`: use a mailbox on your own domain so emails don't land in spam.

If the email can't be sent, the visitor still sees their result, along with a "Try sending again" button.

The script also has built-in spam protection: a hidden honeypot field, a 5-per-15-minutes limit per IP, and server-side validation. The result is recalculated on the server from the answers.

### Optional webhook

To also receive every quiz lead in Make or Zapier, paste the webhook URL into `LEAD_ENDPOINT` near the top of the `<script>` in each page.
