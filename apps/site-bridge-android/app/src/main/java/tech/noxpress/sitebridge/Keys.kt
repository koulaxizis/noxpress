package tech.noxpress.sitebridge

import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.PrivateKey
import java.security.Signature
import java.security.spec.ECGenParameterSpec

/**
 * The device's two EC P-256 keys in the Android Keystore. They never
 * leave the phone.
 *  - read: signs without user authentication (status, log, closing).
 *  - act:  the Keystore signs with it only right after a fingerprint
 *          (or, from Android 11, the screen lock) for that one signature.
 *          New fingerprints invalidate it; the app then asks to pair again.
 */
object Keys {

    private const val STORE = "AndroidKeyStore"
    private const val READ = "sba_read"
    private const val ACT = "sba_act"
    private const val ALGO = "SHA256withECDSA"

    private fun store(): KeyStore = KeyStore.getInstance(STORE).apply { load(null) }

    /** Makes new keys (old ones are replaced) and returns both public keys, base64 DER. */
    fun create(): Pair<String, String> {
        delete()
        return Pair(generate(READ, false), generate(ACT, true))
    }

    private fun generate(alias: String, auth: Boolean): String {
        val spec = KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
        if (auth) {
            spec.setUserAuthenticationRequired(true)
            spec.setInvalidatedByBiometricEnrollment(true)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                // 0 = every single use needs its own authentication.
                spec.setUserAuthenticationParameters(
                    0,
                    KeyProperties.AUTH_BIOMETRIC_STRONG or KeyProperties.AUTH_DEVICE_CREDENTIAL,
                )
            } else {
                @Suppress("DEPRECATION")
                spec.setUserAuthenticationValidityDurationSeconds(-1)
            }
        }
        val gen = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, STORE)
        gen.initialize(spec.build())
        return Protocol.b64(gen.generateKeyPair().public.encoded)
    }

    fun exists(): Boolean = store().let { it.containsAlias(READ) && it.containsAlias(ACT) }

    fun delete() {
        val ks = store()
        listOf(READ, ACT).forEach { if (ks.containsAlias(it)) ks.deleteEntry(it) }
    }

    private fun privateKey(alias: String): PrivateKey =
        store().getKey(alias, null) as? PrivateKey ?: throw IllegalStateException("Missing key $alias")

    fun signRead(message: String): String {
        val s = Signature.getInstance(ALGO)
        s.initSign(privateKey(READ))
        s.update(message.toByteArray(Charsets.UTF_8))
        return Protocol.b64(s.sign())
    }

    /**
     * A Signature ready for the act key, to hand to BiometricPrompt as a
     * CryptoObject. Throws KeyPermanentlyInvalidatedException after new
     * fingerprints.
     */
    fun actSignature(): Signature = Signature.getInstance(ALGO).apply { initSign(privateKey(ACT)) }

    fun finish(sig: Signature, message: String): String {
        sig.update(message.toByteArray(Charsets.UTF_8))
        return Protocol.b64(sig.sign())
    }
}
