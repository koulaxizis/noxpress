import java.net.URI

plugins {
    id("com.android.application")
}

// The app is a Trusted Web Activity of the Live Operator page of one site.
// Another site: ./gradlew assembleRelease -PliveOperatorUrl=https://example.com/live-operator/ -PliveOperatorName="Example Live"
val appUrl: String = (findProperty("liveOperatorUrl") as String?) ?: "https://glarolykoi.net/live-operator/"
val appName: String = (findProperty("liveOperatorName") as String?) ?: "Live Operator"
val appUri: URI = URI(appUrl)
require(appUri.scheme == "https" && !appUri.host.isNullOrEmpty()) { "liveOperatorUrl must be an https URL" }
val appOrigin = "https://" + appUri.host + (if (appUri.port > 0) ":" + appUri.port else "")

// Release signing comes from the environment (GitHub secrets, see
// .github/workflows/android-live-operator.yml): the same key as the Site
// Bridge app. Without it only the debug APK builds.
val keystorePath: String? = System.getenv("SBA_KEYSTORE_FILE")

android {
    namespace = "tech.noxpress.liveoperator"
    compileSdk = 35

    defaultConfig {
        applicationId = "tech.noxpress.liveoperator"
        minSdk = 28
        targetSdk = 35
        // The release workflow tags nox-loa-<versionName>.
        versionCode = 1
        versionName = "1.0.0"

        manifestPlaceholders["hostName"] = appUri.host
        manifestPlaceholders["appPath"] = appUri.path.ifEmpty { "/" }
        resValue("string", "app_name", appName)
        resValue("string", "launch_url", appUrl)
        resValue(
            "string",
            "asset_statements",
            "[{\\\"relation\\\": [\\\"delegate_permission/common.handle_all_urls\\\"], \\\"target\\\": {\\\"namespace\\\": \\\"web\\\", \\\"site\\\": \\\"$appOrigin\\\"}}]"
        )
    }

    signingConfigs {
        // The same public debug key as the Site Bridge app (debug builds only),
        // so a debug APK from any CI run installs over the previous one.
        getByName("debug") {
            storeFile = file("debug.keystore")
            storePassword = "android"
            keyAlias = "androiddebugkey"
            keyPassword = "android"
        }
        if (keystorePath != null) {
            create("release") {
                storeFile = file(keystorePath)
                storePassword = System.getenv("SBA_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("SBA_KEY_ALIAS")
                keyPassword = System.getenv("SBA_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (keystorePath != null) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    androidResources {
        generateLocaleConfig = true
    }
}

dependencies {
    // Trusted Web Activity launcher, notification delegation and the
    // Android 13 notification permission (Google, exact version).
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.5.0")
}
