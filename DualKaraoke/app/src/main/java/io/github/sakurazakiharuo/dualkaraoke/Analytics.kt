package io.github.sakurazakiharuo.dualkaraoke

import android.content.Context
import android.media.AudioDeviceInfo
import android.os.Bundle
import com.google.firebase.analytics.FirebaseAnalytics

/**
 * アクセス解析（Firebase Analytics）。
 * 使い方の傾向だけを記録し、曲名やファイル名などは送らない。
 */
class Analytics(context: Context) {

    private val firebase = FirebaseAnalytics.getInstance(context)

    fun setEnabled(enabled: Boolean) {
        firebase.setAnalyticsCollectionEnabled(enabled)
    }

    /** 再生を始めたとき（一時停止からの再開は含まない）。 */
    fun logPlayStart(
        vocalDevice: AudioDeviceInfo?,
        karaokeDevice: AudioDeviceInfo?,
        hasVocal: Boolean,
        hasKaraoke: Boolean,
        outputMode: Int,
        offsetMs: Int,
        fromPreset: Boolean,
    ) {
        firebase.logEvent("play_start", Bundle().apply {
            putString("vocal_output", deviceKey(vocalDevice))
            putString("karaoke_output", deviceKey(karaokeDevice))
            putString("output_pair", "${deviceKey(vocalDevice)}+${deviceKey(karaokeDevice)}")
            putLong("has_vocal", if (hasVocal) 1 else 0)
            putLong("has_karaoke", if (hasKaraoke) 1 else 0)
            putLong("output_mode", outputMode.toLong())
            putLong("offset_ms", offsetMs.toLong())
            putLong("from_preset", if (fromPreset) 1 else 0)
        })
    }

    fun logPresetSelect(slot: Int) {
        firebase.logEvent("preset_select", Bundle().apply { putLong("slot", slot.toLong()) })
    }

    fun logPresetRegister(slot: Int) {
        firebase.logEvent("preset_register", Bundle().apply { putLong("slot", slot.toLong()) })
    }

    fun logOutputModeChange(mode: Int) {
        firebase.logEvent("output_mode_change", Bundle().apply { putLong("mode", mode.toLong()) })
    }

    /** 機器名は送らず、種類だけを送る。 */
    private fun deviceKey(device: AudioDeviceInfo?): String = when (device?.type) {
        null -> "auto"
        AudioDeviceInfo.TYPE_BLUETOOTH_A2DP -> "bluetooth"
        AudioDeviceInfo.TYPE_BLE_HEADSET, AudioDeviceInfo.TYPE_BLE_SPEAKER -> "bluetooth_le"
        AudioDeviceInfo.TYPE_HEARING_AID -> "hearing_aid"
        AudioDeviceInfo.TYPE_USB_DEVICE, AudioDeviceInfo.TYPE_USB_HEADSET,
        AudioDeviceInfo.TYPE_USB_ACCESSORY -> "usb"
        AudioDeviceInfo.TYPE_WIRED_HEADSET, AudioDeviceInfo.TYPE_WIRED_HEADPHONES -> "wired"
        AudioDeviceInfo.TYPE_LINE_ANALOG, AudioDeviceInfo.TYPE_LINE_DIGITAL,
        AudioDeviceInfo.TYPE_AUX_LINE -> "line"
        AudioDeviceInfo.TYPE_HDMI -> "hdmi"
        AudioDeviceInfo.TYPE_BUILTIN_SPEAKER -> "speaker"
        else -> "other"
    }
}
