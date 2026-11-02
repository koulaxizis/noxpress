plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// The app is a Trusted Web Activity of the Live Operator page of the site
// it is connected to (2.0.0: one app for every site, set on its connect screen).

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
        versionCode = 7
        versionName = "2.0.0"
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
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        buildConfig = true
    }
    androidResources {
        generateLocaleConfig = true
    }
}

dependencies {
    // Trusted Web Activity launcher, notification delegation and the
    // Android 13 notification permission (Google, exact version).
    implementation("com.google.androidbrowserhelper:androidbrowserhelper:2.5.0")
    // The new-version notification (1.2.0).
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.work:work-runtime-ktx:2.9.1")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
}
