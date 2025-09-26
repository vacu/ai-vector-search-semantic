<?php
if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="setup-flow">
    <div class="setup-step">
        <h3>&#x1F680; Step 1: Create Your Supabase Project</h3>
        <ol>
            <li>Go to <a href="https://app.supabase.io/" target="_blank" rel="noopener">supabase.com</a> and create a free account</li>
            <li>Click "New Project" and choose your organization</li>
            <li>Set a project name and strong database password</li>
            <li>Choose a region close to your users</li>
            <li>Wait 2-3 minutes for project setup to complete</li>
        </ol>
    </div>

    <div class="setup-step">
        <h3>&#x1F511; Step 2: Get Your API Credentials</h3>
        <ol>
            <li>In your Supabase project, go to <strong>Settings &rarr; API</strong></li>
            <li>Copy your <strong>Project URL</strong> (looks like: <code>https://xyz.supabase.co</code>)</li>
            <li>Copy your <strong>service_role</strong> key from "Project API keys" section</li>
            <li>Paste both values in the configuration form above &#x2B06;</li>
        </ol>
    </div>

    <div class="setup-step">
        <h3>&#x1F517; Step 3: Get PostgreSQL Connection String (for WP-CLI)</h3>
        <ol>
            <li>In your Supabase project, go to <strong>Settings &rarr; Database</strong></li>
            <li>Scroll down to <strong>"Connection parameters"</strong></li>
            <li>Copy the <strong>"Connection string"</strong> in URI format</li>
            <li>Paste it in the PostgreSQL Connection String field above &#x2B06;</li>
        </ol>
        <div class="notice notice-info inline setup-notice">
            <p><strong>&#x1F4A1; Why PostgreSQL connection?</strong> This enables professional WP-CLI commands for reliable schema installation:</p>
            <ul class="setup-cli-list">
                <li><code>wp aivs install-schema</code> - One-command schema installation</li>
                <li><code>wp aivs sync-products</code> - Bulk product synchronization</li>
                <li><code>wp aivs check-schema</code> - Comprehensive status checking</li>
            </ul>
        </div>
    </div>

    <div class="setup-step">
        <h3>&#x1F916; Step 4: OpenAI Setup (Optional)</h3>
        <p>For AI semantic search, you'll need an OpenAI API key:</p>
        <ol>
            <li>Visit <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI API Keys</a></li>
            <li>Create a new API key</li>
            <li>Add billing information (required for API usage)</li>
            <li>Paste the key in the OpenAI field above &#x2B06;</li>
        </ol>
        <div class="notice notice-warning inline setup-notice">
            <p><strong>&#x1F4B0; Cost:</strong> Embeddings cost ~$0.05-$1.00 per 1,000 products (one-time setup cost)</p>
        </div>
    </div>
</div>