package com.bpm.astawidya

// Pakai FlutterFragmentActivity (bukan FlutterActivity) supaya plugin
// local_auth (sidik jari) bisa attach ke FragmentActivity. Tanpa ini,
// pemanggilan BiometricService.authenticate() gagal dengan
// PlatformException(no_fragment_activity, ...). Mirror dari BEM.
import io.flutter.embedding.android.FlutterFragmentActivity

class MainActivity : FlutterFragmentActivity()
