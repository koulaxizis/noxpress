plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

// Release signing comes from the environment (GitHub secrets, see
// .github/workflows/android.yml). Without it only the debug APK builds.
val keystorePath: String? = System.getenv("SBA_KEYSTORE_FILE")

android {
    namespace = "tech.noxpress.sitebridge"
    compileSdk = 35

    defaultConfig {
        applicationId = "tech.noxpress.sitebridge"
        minSdk = 28
        targetSdk = 35
        // The release workflow tags nox-sba-<versionName>.
        versionCode = 2
        versionName = "1.1.0"
    }

    signingConfigs {
        // A fixed debug key in the repo (not a secret: debug builds only), so a
        // debug APK from any CI run installs over the previous one. The runner's
        // own debug key changes on every run.
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
        compose = true
        buildConfig = true
    }
    androidResources {
        generateLocaleConfig = true
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.fragment:fragment-ktx:1.8.5")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
    implementation("com.google.android.gms:play-services-code-scanner:16.1.0")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
}
