<?php

/**
 * ChorManager Single-Sign-On plugin for SnappyMail.
 *
 * Consumes a short-lived, libsodium-encrypted token issued by ChorManager
 * (App\Services\SnappymailSsoTokenService) at
 * GET /webmail/?chormanager-sso&token=... and logs the user straight into
 * their IMAP mailbox via Actions::LoginProcess(), without a second login
 * prompt.
 *
 * Trust boundary: the token is encrypted with SNAPPYMAIL_SSO_SECRET, a
 * secret shared only between ChorManager and this plugin - it is NOT the
 * same key ChorManager uses to encrypt stored IMAP credentials at rest
 * (MAIL_CREDENTIAL_KEY). Never reuse that key here.
 *
 * Every failure path is fail-closed: log a safe (no secret/password)
 * message and redirect to the plain /webmail/ login screen. Nothing is
 * ever echoed to the response on error.
 *
 * Deliberately declared in the GLOBAL namespace (no "namespace" statement):
 * RainLoop\Plugins\Manager::loadPluginByName() resolves the plugin class via
 * a bare, unqualified class_exists($sClassName)/is_subclass_of($sClassName,
 * 'RainLoop\Plugins\AbstractPlugin') check (Manager.php:142-145) where
 * $sClassName is the unqualified "ChormanagerSsoPlugin" computed from the
 * folder name - confirmed empirically: declaring this class inside
 * "namespace RainLoop\Plugins;" made it fully-qualified as
 * RainLoop\Plugins\ChormanagerSsoPlugin, which class_exists('ChormanagerSsoPlugin')
 * does not find, producing "Invalid plugin class ChormanagerSsoPlugin" in the
 * SnappyMail log and the plugin silently not loading.
 */
class ChormanagerSsoPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	const
		NAME        = 'ChorManager SSO',
		AUTHOR      = 'ChorManager',
		URL         = 'https://github.com/',
		VERSION     = '1.0.0',
		RELEASE     = '2026-06-29',
		REQUIRED    = '2.24.3',
		CATEGORY    = 'Login',
		LICENSE     = 'Proprietary',
		DESCRIPTION = 'Consumes a ChorManager-issued SSO token and logs the user into their IMAP mailbox.';

	/** Token lifetime tolerance is enforced via the payload's own "exp"; this is just the replay-marker sweep window. */
	const REPLAY_MARKER_MAX_AGE_SECONDS = 600;

	public function Init(): void
	{
		$this->addPartHook('chormanager-sso', 'DoSso');
	}

	public function DoSso(...$args): bool
	{
		try {
			$this->handleSso();
		} catch (\Throwable $oException) {
			$this->safeWriteLog(
				'chormanager_sso.unexpected_error: ' . \get_class($oException) . ': ' . $oException->getMessage()
			);
		}

		// The inbound URL carries the SSO token in its query string. Tell the
		// browser not to leak that URL as a Referer on the redirect target or
		// any resource it subsequently loads.
		\header('Referrer-Policy: no-referrer');
		\header('Location: /webmail/');
		return true;
	}

	private function handleSso(): void
	{
		$sToken = (string) ($_GET['token'] ?? '');
		if ('' === $sToken) {
			$this->safeWriteLog('chormanager_sso.missing_token');
			return;
		}

		$sSecretB64 = (string) \getenv('SNAPPYMAIL_SSO_SECRET');
		if ('' === $sSecretB64) {
			$this->safeWriteLog('chormanager_sso.misconfigured');
			return;
		}

		$sKey = \base64_decode($sSecretB64, true);
		if (false === $sKey || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($sKey)) {
			$this->safeWriteLog('chormanager_sso.misconfigured');
			return;
		}

		$aPayload = $this->decryptPayload($sToken, $sKey);
		if (null === $aPayload) {
			$this->safeWriteLog('chormanager_sso.invalid_token');
			return;
		}

		if (!isset($aPayload['exp']) || \time() > (int) $aPayload['exp']) {
			$this->safeWriteLog('chormanager_sso.expired');
			return;
		}

		$sJti = (string) ($aPayload['jti'] ?? '');
		if ('' === $sJti || !\preg_match('/^[a-f0-9]+$/', $sJti)) {
			$this->safeWriteLog('chormanager_sso.invalid_token');
			return;
		}

		$sMarkerDir = $this->replayMarkerDir();
		$this->sweepOldMarkers($sMarkerDir);

		// Atomically claim the token. fopen() with mode 'x' (O_CREAT|O_EXCL)
		// fails if the marker already exists, closing the TOCTOU race that a
		// plain file_exists()+touch() leaves open: two concurrent requests
		// replaying the same token could otherwise both pass the existence
		// check before either created the marker. The marker is claimed BEFORE
		// attempting login, so a token can never be used twice even if
		// LoginProcess() itself fails halfway.
		$sMarkerFile = $sMarkerDir . $sJti;
		$rMarker = @\fopen($sMarkerFile, 'x');
		if (false === $rMarker) {
			$this->safeWriteLog('chormanager_sso.replay');
			return;
		}
		\fclose($rMarker);

		$sEmail = (string) ($aPayload['email'] ?? '');
		$sPassword = (string) ($aPayload['password'] ?? '');
		if ('' === $sEmail || '' === $sPassword) {
			$this->safeWriteLog('chormanager_sso.invalid_token');
			return;
		}

		$sImapHost = (string) ($aPayload['imap_host'] ?? '');
		$iImapPort = (int) ($aPayload['imap_port'] ?? 993);
		$sImapEnc  = (string) ($aPayload['imap_enc'] ?? 'ssl');
		$sSmtpHost = (string) ($aPayload['smtp_host'] ?? '');
		$iSmtpPort = (int) ($aPayload['smtp_port'] ?? 0);
		$sSmtpEnc  = (string) ($aPayload['smtp_enc'] ?? '');
		if ('' !== $sImapHost) {
			$this->ensureDomainConfig($sEmail, $sImapHost, $iImapPort, $sImapEnc, $sSmtpHost, $iSmtpPort, $sSmtpEnc);
		}

		try {
			$this->Manager()->Actions()->LoginProcess(
				$sEmail,
				new \SnappyMail\SensitiveString($sPassword),
				true
			);
			$this->safeWriteLog('chormanager_sso.login_attempted');
		} catch (\Throwable $oException) {
			$this->safeWriteLog(
				'chormanager_sso.login_failed: ' . \get_class($oException) . ': ' . $oException->getMessage()
			);
		}
	}

	/**
	 * @return array|null Decoded JSON payload, or null on any decode/decrypt failure.
	 */
	private function decryptPayload(string $sToken, string $sKey): ?array
	{
		$sRaw = \base64_decode($sToken, true);
		if (false === $sRaw || \strlen($sRaw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			return null;
		}

		$sNonce = \substr($sRaw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$sCiphertext = \substr($sRaw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		$sPlaintext = \sodium_crypto_secretbox_open($sCiphertext, $sNonce, $sKey);
		if (false === $sPlaintext) {
			return null;
		}

		$aPayload = \json_decode($sPlaintext, true);
		if (!\is_array($aPayload)) {
			return null;
		}

		return $aPayload;
	}

	/**
	 * Write (or overwrite) the SnappyMail domain JSON for the email's domain so
	 * that LoginProcess() connects to the correct IMAP server instead of the
	 * default localhost:143 fallback.
	 *
	 * SnappyMail resolves the IMAP server by loading
	 * APP_PRIVATE_DATA/domains/{domain}.json at login time. Without this file
	 * the default.json (localhost:143) is used, causing ConnectionError.
	 *
	 * SECURITY / multi-tenant note: this file is keyed by e-mail DOMAIN and is
	 * therefore shared by every ChorManager user on that provider. A user who
	 * supplies a malicious imap_host for a shared domain could poison it for
	 * others. Two things contain that: (1) we ALWAYS rewrite the file with the
	 * current user's own host immediately before LoginProcess(), so the value
	 * in effect at login is this user's own configuration - do NOT refactor
	 * this into a "skip write if file exists" optimisation, as that would
	 * reopen the hole; (2) TLS peer verification (verify_peer_name below) is
	 * left enabled so a redirected host must still present a valid certificate
	 * for the name it claims.
	 *
	 * imap_enc mapping (ChorManager → MailSo\Net\Enumerations\ConnectionSecurityType):
	 *   'ssl'  → 1  (direct TLS, port 993)
	 *   'tls'  → 2  (STARTTLS, port 143/587)
	 *   'none' → 0  (plain)
	 */
	private function ensureDomainConfig(
		string $sEmail,
		string $sImapHost,
		int $iImapPort,
		string $sImapEnc,
		string $sSmtpHost = '',
		int $iSmtpPort = 0,
		string $sSmtpEnc = ''
	): void {
		$iAtPos = \strrpos($sEmail, '@');
		if (false === $iAtPos) {
			return;
		}

		$sDomain = \substr($sEmail, $iAtPos + 1);
		// Restrict to valid hostname characters to prevent filesystem path injection.
		$sSafeDomain = \preg_replace('/[^a-zA-Z0-9.\-]/', '', $sDomain);
		if ('' === $sSafeDomain || \strlen($sSafeDomain) > 253) {
			return;
		}

		$iType = match ($sImapEnc) {
			'ssl'   => 1,
			'tls'   => 2,
			default => 0,
		};

		$aSslConfig = [
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
			'SNI_enabled'       => true,
			'disable_compression' => true,
			'security_level'    => 1,
		];

		$aConfig = [
			'IMAP' => [
				'host'            => $sImapHost,
				'port'            => $iImapPort,
				'type'            => $iType,
				'timeout'         => 300,
				'shortLogin'      => false,
				'lowerLogin'      => false,
				'sasl'            => ['PLAIN', 'LOGIN'],
				'ssl'             => $aSslConfig,
				'disabled_capabilities' => ['METADATA', 'OBJECTID', 'PREVIEW', 'STATUS=SIZE'],
				'use_expunge_all_on_delete' => false,
				'fast_simple_search' => true,
				'force_select'    => false,
				'message_all_headers' => false,
				'message_list_limit' => 10000,
				'search_filter'   => '',
			],
			'SMTP' => [
				'host'       => '' !== $sSmtpHost ? $sSmtpHost : 'localhost',
				'port'       => $iSmtpPort > 0 ? $iSmtpPort : 25,
				'type'       => '' !== $sSmtpHost ? match ($sSmtpEnc) { 'ssl' => 1, 'tls' => 2, default => 0 } : 0,
				'timeout'    => 60,
				'shortLogin' => false,
				'lowerLogin' => false,
				'sasl'       => ['PLAIN', 'LOGIN'],
				'ssl'        => '' !== $sSmtpHost ? $aSslConfig : (object) [],
				'useAuth'    => '' !== $sSmtpHost,
				'setSender'  => false,
				'usePhpMail' => false,
			],
			'Sieve' => [
				'host'        => 'localhost',
				'port'        => 4190,
				'type'        => 0,
				'timeout'     => 10,
				'shortLogin'  => false,
				'lowerLogin'  => false,
				'sasl'        => ['PLAIN', 'LOGIN'],
				'ssl'         => (object) [],
				'enabled'     => false,
			],
		];

		$sDomainFile = \APP_PRIVATE_DATA . 'domains/' . $sSafeDomain . '.json';
		$sJson = \json_encode($aConfig, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
		if (false === $sJson) {
			$this->safeWriteLog('chormanager_sso.domain_config_encode_failed');
			return;
		}

		if (false === \file_put_contents($sDomainFile, $sJson)) {
			$this->safeWriteLog('chormanager_sso.domain_config_write_failed');
		}
	}

	private function replayMarkerDir(): string
	{
		$sDir = \APP_PRIVATE_DATA . 'chormanager_sso_used/';
		if (!\is_dir($sDir)) {
			@\mkdir($sDir, 0750, true);
		}

		return $sDir;
	}

	private function sweepOldMarkers(string $sDir): void
	{
		$aFiles = \glob($sDir . '*');
		if (!\is_array($aFiles)) {
			return;
		}

		$iThreshold = \time() - self::REPLAY_MARKER_MAX_AGE_SECONDS;
		foreach ($aFiles as $sFile) {
			$iMtime = @\filemtime($sFile);
			if (false !== $iMtime && $iMtime < $iThreshold) {
				@\unlink($sFile);
			}
		}
	}

	/**
	 * Log via the plugin manager's logger if reachable; otherwise fall back
	 * to error_log() as a last resort. Never include token/ciphertext or
	 * password material in the message.
	 *
	 * Uses LOG_WARNING explicitly: the default application.ini ships with
	 * [logs] level = 4 (Warning), and MailSo\Log\Logger::Write() silently
	 * drops anything less severe than the configured threshold (confirmed
	 * empirically - calls using WriteLog()'s own LOG_INFO default never
	 * appeared in `docker logs`). Every call site here is a fail-closed
	 * security-relevant event, so Warning is an appropriate floor.
	 */
	private function safeWriteLog(string $sMessage): void
	{
		try {
			$oManager = $this->Manager();
			if ($oManager && \method_exists($oManager, 'WriteLog')) {
				$oManager->WriteLog($sMessage, \LOG_WARNING);
				return;
			}
		} catch (\Throwable $oException) {
			// fall through to error_log below
		}

		\error_log('[chormanager-sso] ' . $sMessage);
	}
}
