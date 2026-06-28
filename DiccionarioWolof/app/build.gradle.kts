plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// Versión: la CI puede pasar -PappVersionCode / -PappVersionName para que cada
// compilación sea distinta (y se vea dentro de la app). Por defecto, 1 / dev.
val appVersionCode = (project.findProperty("appVersionCode") as String?)?.toIntOrNull() ?: 1
val appVersionName = (project.findProperty("appVersionName") as String?) ?: "1.0-dev"

android {
    namespace = "com.bomedia.diccionariowolof"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.bomedia.diccionariowolof"
        minSdk = 24          // Android 7.0 (Nougat) o superior
        targetSdk = 34
        versionCode = appVersionCode
        versionName = appVersionName
    }

    // Firma de depuración ESTABLE (clave incluida en el repo). Así todas las
    // compilaciones tienen la misma firma y se pueden actualizar entre sí en el
    // móvil sin tener que desinstalar.
    signingConfigs {
        getByName("debug") {
            storeFile = file("debug.keystore")
            storePassword = "android"
            keyAlias = "androiddebugkey"
            keyPassword = "android"
        }
    }

    buildTypes {
        release {
            // App sencilla y offline: sin ofuscación para facilitar su lectura.
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
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
        buildConfig = true   // para exponer BuildConfig.VERSION_NAME en la app
    }

    composeOptions {
        // Compatible con Kotlin 1.9.24.
        kotlinCompilerExtensionVersion = "1.5.14"
    }
}

dependencies {
    // Jetpack Compose mediante BOM (versiones coordinadas automáticamente).
    val composeBom = platform("androidx.compose:compose-bom:2024.06.00")
    implementation(composeBom)

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.9.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.2")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.2")
    // Aporta collectAsStateWithLifecycle().
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.2")

    // Material Design 3.
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
