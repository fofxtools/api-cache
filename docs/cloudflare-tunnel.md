# Cloudflare Tunnel for Local Development

When testing webhooks or APIs that need to reach your local development server, you need to expose your local server to the internet. This is commonly required for services like DataForSEO webhooks that need to send POST requests back to your application.

Cloudflare Tunnel is an excellent solution for this need.

## Installation

1. **Install cloudflared**: Follow the installation guide at https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/install-and-setup/installation/

## Usage

2. **Start the tunnel**: Run the following command to create a tunnel to your local server:
   ```bash
   cloudflared tunnel --url http://localhost:8000
   ```

3. **Get your public URL**: The command will output a public URL like:
   ```
   https://abc-123.trycloudflare.com
   ```

4. **Use the tunnel URL**: Use this public URL for webhook endpoints in your API configurations.

## Example: DataForSEO Webhooks

- **Local server**: `http://localhost:8000`
- **Cloudflare tunnel**: `https://abc-123.trycloudflare.com`  
- **Webhook URL**: `https://abc-123.trycloudflare.com/postback.php`

This allows external services to reach your local development environment without exposing your machine directly to the internet.

## Development Workflow

1. Start your local development server (e.g., `php -S 0.0.0.0:8000 -t public`)
2. In another terminal, start the Cloudflare tunnel
3. Copy the generated tunnel URL
4. Configure your webhook endpoints to use the tunnel URL
5. Test your webhook integrations with real external services

**Important**: The tunnel, PHP server, and any scripts you run should all be started in the **same environment** (WSL or CMD) for webhooks to function properly.