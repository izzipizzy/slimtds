<?php
/**
 * Browser half of the WebAuthn (YubiKey) flows. Included by the login page and
 * the Security settings tab. The server sends binary fields base64url-encoded;
 * navigator.credentials wants ArrayBuffers, and its results go back the same way.
 *
 * @var string $csrf_token
 */
?>
<script>
window.slimWebauthn = (function () {
    function toBuf(s) {
        s = s.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = atob(s), out = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    }
    function toB64(buf) {
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function creds(list) {
        return (list || []).map(function (c) { return Object.assign({}, c, { id: toBuf(c.id) }); });
    }
    async function post(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': <?= json_encode($csrf_token) ?> },
            body: JSON.stringify(body || {})
        });
        var data = null;
        try { data = await res.json(); } catch (_) {}
        if (!res.ok) throw new Error((data && data.error) || ('HTTP ' + res.status));
        return data;
    }
    function supported() {
        return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    }
    function niceError(e) {
        if (e && e.name === 'NotAllowedError') return <?= json_encode(t('auth.webauthn_cancelled')) ?>;
        if (e && e.name === 'InvalidStateError') return <?= json_encode(t('settings.security.already_added')) ?>;
        return (e && e.message) || String(e);
    }

    return {
        supported: supported,
        niceError: niceError,

        async login(login) {
            var opt = await post('/admin/login/webauthn/options', { login: login });
            var pk = opt.publicKey;
            pk.challenge = toBuf(pk.challenge);
            pk.allowCredentials = creds(pk.allowCredentials);
            var cred = await navigator.credentials.get({ publicKey: pk });
            var r = cred.response;
            return post('/admin/login/webauthn', {
                id: toB64(cred.rawId),
                clientDataJSON: toB64(r.clientDataJSON),
                authenticatorData: toB64(r.authenticatorData),
                signature: toB64(r.signature)
            });
        },

        async register(name) {
            var opt = await post('/admin/settings/security/webauthn/options', {});
            var pk = opt.publicKey;
            pk.challenge = toBuf(pk.challenge);
            pk.user.id = toBuf(pk.user.id);
            pk.excludeCredentials = creds(pk.excludeCredentials);
            var cred = await navigator.credentials.create({ publicKey: pk });
            var r = cred.response;
            return post('/admin/settings/security/webauthn', {
                name: name,
                clientDataJSON: toB64(r.clientDataJSON),
                attestationObject: toB64(r.attestationObject)
            });
        }
    };
})();
</script>
