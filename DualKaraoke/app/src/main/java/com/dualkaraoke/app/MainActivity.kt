package com.dualkaraoke.app

import android.app.Activity
import android.content.Intent
import android.content.SharedPreferences
import android.media.AudioDeviceCallback
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.OpenableColumns
import android.view.View
import android.view.WindowManager
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.SeekBar
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import com.dualkaraoke.app.DualPlayer.Role

class MainActivity : Activity() {

    private data class DeviceItem(val device: AudioDeviceInfo?, val label: String)

    private lateinit var audioManager: AudioManager
    private lateinit var player: DualPlayer
    private lateinit var prefs: SharedPreferences
    private val handler = Handler(Looper.getMainLooper())

    private lateinit var vocalFileName: TextView
    private lateinit var karaokeFileName: TextView
    private lateinit var vocalSpinner: Spinner
    private lateinit var karaokeSpinner: Spinner
    private lateinit var warning: TextView
    private lateinit var offsetLabel: TextView
    private lateinit var offsetSeek: SeekBar
    private lateinit var positionSeek: SeekBar
    private lateinit var positionText: TextView
    private lateinit var playButton: Button

    private var devices: List<DeviceItem> = emptyList()
    private var vocalDeviceId: Int? = null
    private var karaokeDeviceId: Int? = null
    private var updatingSpinners = false
    private var userSeeking = false

    private val deviceCallback = object : AudioDeviceCallback() {
        override fun onAudioDevicesAdded(addedDevices: Array<out AudioDeviceInfo>) = refreshDevices()
        override fun onAudioDevicesRemoved(removedDevices: Array<out AudioDeviceInfo>) = refreshDevices()
    }

    private val uiLoop = object : Runnable {
        override fun run() {
            updatePosition()
            handler.postDelayed(this, 250)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        audioManager = getSystemService(AudioManager::class.java)
        prefs = getSharedPreferences("settings", MODE_PRIVATE)
        player = DualPlayer(this).apply {
            onError = { Toast.makeText(this@MainActivity, it, Toast.LENGTH_LONG).show() }
            onCompletion = { updatePlayButton() }
        }

        vocalFileName = findViewById(R.id.vocalFileName)
        karaokeFileName = findViewById(R.id.karaokeFileName)
        vocalSpinner = findViewById(R.id.vocalDevice)
        karaokeSpinner = findViewById(R.id.karaokeDevice)
        warning = findViewById(R.id.warning)
        offsetLabel = findViewById(R.id.offsetLabel)
        offsetSeek = findViewById(R.id.offsetSeek)
        positionSeek = findViewById(R.id.positionSeek)
        positionText = findViewById(R.id.positionText)
        playButton = findViewById(R.id.playButton)

        findViewById<Button>(R.id.vocalFileButton).setOnClickListener { pickFile(REQUEST_VOCAL) }
        findViewById<Button>(R.id.karaokeFileButton).setOnClickListener { pickFile(REQUEST_KARAOKE) }
        findViewById<Button>(R.id.refreshButton).setOnClickListener { refreshDevices() }

        vocalSpinner.onItemSelectedListener = deviceListener(Role.VOCAL)
        karaokeSpinner.onItemSelectedListener = deviceListener(Role.KARAOKE)

        setupVolume(R.id.vocalVolume, Role.VOCAL)
        setupVolume(R.id.karaokeVolume, Role.KARAOKE)
        setupOffset()
        setupTransport()

        vocalDeviceId = prefs.getInt(KEY_VOCAL_DEVICE, -1).takeIf { it >= 0 }
        karaokeDeviceId = prefs.getInt(KEY_KARAOKE_DEVICE, -1).takeIf { it >= 0 }
        refreshDevices()
        audioManager.registerAudioDeviceCallback(deviceCallback, handler)

        restoreSource(Role.VOCAL, KEY_VOCAL_URI)
        restoreSource(Role.KARAOKE, KEY_KARAOKE_URI)

        handler.post(uiLoop)
    }

    override fun onDestroy() {
        handler.removeCallbacks(uiLoop)
        audioManager.unregisterAudioDeviceCallback(deviceCallback)
        player.release()
        super.onDestroy()
    }

    // ---- 音源ファイル ----

    private fun pickFile(requestCode: Int) {
        val intent = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = "audio/*"
        }
        startActivityForResult(intent, requestCode)
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        val uri = data?.data
        if (resultCode != RESULT_OK || uri == null) return
        try {
            contentResolver.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION)
        } catch (_: SecurityException) {
        }
        when (requestCode) {
            REQUEST_VOCAL -> loadSource(Role.VOCAL, uri, KEY_VOCAL_URI)
            REQUEST_KARAOKE -> loadSource(Role.KARAOKE, uri, KEY_KARAOKE_URI)
        }
    }

    private fun restoreSource(role: Role, key: String) {
        val saved = prefs.getString(key, null) ?: return
        val uri = Uri.parse(saved)
        val stillGranted = contentResolver.persistedUriPermissions.any { it.uri == uri && it.isReadPermission }
        if (stillGranted) loadSource(role, uri, key) else prefs.edit().remove(key).apply()
    }

    private fun loadSource(role: Role, uri: Uri, key: String) {
        val ok = player.setSource(role, uri)
        val label = if (ok) displayName(uri) else getString(R.string.no_file)
        (if (role == Role.VOCAL) vocalFileName else karaokeFileName).text = label
        prefs.edit().apply { if (ok) putString(key, uri.toString()) else remove(key) }.apply()
        positionSeek.max = player.durationMs()
        updatePlayButton()
    }

    private fun displayName(uri: Uri): String {
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use {
            if (it.moveToFirst()) return it.getString(0)
        }
        return uri.lastPathSegment ?: uri.toString()
    }

    // ---- 出力機器 ----

    private fun refreshDevices() {
        val seen = HashSet<String>()
        val found = audioManager.getDevices(AudioManager.GET_DEVICES_OUTPUTS)
            .filter { typeLabel(it.type) != null }
            .filter { seen.add("${it.type}/${it.productName}") }
            .map { DeviceItem(it, "${typeLabel(it.type)}：${it.productName}") }
        devices = listOf(DeviceItem(null, getString(R.string.auto_device))) + found

        // 初回は「イヤホン=Bluetooth」「スピーカー=USB→有線→本体」を自動で選ぶ
        if (vocalDeviceId == null || devices.none { it.device?.id == vocalDeviceId }) {
            vocalDeviceId = found.firstOrNull { isBluetooth(it.device!!.type) }?.device?.id
        }
        if (karaokeDeviceId == null || devices.none { it.device?.id == karaokeDeviceId }) {
            karaokeDeviceId = (found.firstOrNull { isUsb(it.device!!.type) }
                ?: found.firstOrNull { !isBluetooth(it.device!!.type) && it.device.type != AudioDeviceInfo.TYPE_BUILTIN_SPEAKER }
                ?: found.firstOrNull { it.device!!.type == AudioDeviceInfo.TYPE_BUILTIN_SPEAKER })?.device?.id
        }

        updatingSpinners = true
        val labels = devices.map { it.label }
        for ((spinner, id) in listOf(vocalSpinner to vocalDeviceId, karaokeSpinner to karaokeDeviceId)) {
            spinner.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, labels)
            spinner.setSelection(devices.indexOfFirst { it.device?.id == id }.coerceAtLeast(0))
        }
        updatingSpinners = false

        applyDevice(Role.VOCAL)
        applyDevice(Role.KARAOKE)
    }

    private fun deviceListener(role: Role) = object : AdapterView.OnItemSelectedListener {
        override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
            if (updatingSpinners) return
            val deviceId = devices.getOrNull(position)?.device?.id
            if (role == Role.VOCAL) vocalDeviceId = deviceId else karaokeDeviceId = deviceId
            prefs.edit()
                .putInt(if (role == Role.VOCAL) KEY_VOCAL_DEVICE else KEY_KARAOKE_DEVICE, deviceId ?: -1)
                .apply()
            applyDevice(role)
        }

        override fun onNothingSelected(parent: AdapterView<*>?) {}
    }

    private fun applyDevice(role: Role) {
        val id = if (role == Role.VOCAL) vocalDeviceId else karaokeDeviceId
        player.setDevice(role, devices.firstOrNull { it.device?.id == id }?.device)
        updateWarning()
    }

    private fun updateWarning() {
        val vocal = devices.firstOrNull { it.device?.id == vocalDeviceId }?.device
        val karaoke = devices.firstOrNull { it.device?.id == karaokeDeviceId }?.device
        val message = when {
            vocal != null && karaoke != null && vocal.id == karaoke.id ->
                "イヤホンとスピーカーに同じ機器が選ばれています。"
            vocal != null && karaoke != null && isBluetooth(vocal.type) && isBluetooth(karaoke.type) ->
                "両方Bluetoothになっています。Androidは通常Bluetoothで同時に1台しか鳴らせないため、片方はUSB・有線・本体スピーカーにしてください。"
            vocal == null || karaoke == null ->
                "「自動」の出力は両方の音が同じ機器から出る場合があります。"
            else -> null
        }
        warning.text = message
        warning.visibility = if (message == null) View.GONE else View.VISIBLE
    }

    private fun typeLabel(type: Int): String? = when (type) {
        AudioDeviceInfo.TYPE_BLUETOOTH_A2DP -> "Bluetooth"
        AudioDeviceInfo.TYPE_BLE_HEADSET, AudioDeviceInfo.TYPE_BLE_SPEAKER -> "Bluetooth LE"
        AudioDeviceInfo.TYPE_HEARING_AID -> "補聴器"
        AudioDeviceInfo.TYPE_USB_DEVICE, AudioDeviceInfo.TYPE_USB_HEADSET,
        AudioDeviceInfo.TYPE_USB_ACCESSORY -> "USB"
        AudioDeviceInfo.TYPE_WIRED_HEADSET, AudioDeviceInfo.TYPE_WIRED_HEADPHONES -> "有線"
        AudioDeviceInfo.TYPE_LINE_ANALOG, AudioDeviceInfo.TYPE_LINE_DIGITAL,
        AudioDeviceInfo.TYPE_AUX_LINE -> "ライン出力"
        AudioDeviceInfo.TYPE_HDMI -> "HDMI"
        AudioDeviceInfo.TYPE_BUILTIN_SPEAKER -> "本体スピーカー"
        else -> null
    }

    private fun isBluetooth(type: Int) = type == AudioDeviceInfo.TYPE_BLUETOOTH_A2DP ||
        type == AudioDeviceInfo.TYPE_BLE_HEADSET || type == AudioDeviceInfo.TYPE_BLE_SPEAKER ||
        type == AudioDeviceInfo.TYPE_HEARING_AID

    private fun isUsb(type: Int) = type == AudioDeviceInfo.TYPE_USB_DEVICE ||
        type == AudioDeviceInfo.TYPE_USB_HEADSET || type == AudioDeviceInfo.TYPE_USB_ACCESSORY

    // ---- 音量・ずれ補正 ----

    private fun setupVolume(seekId: Int, role: Role) {
        val key = "volume_$role"
        val seek = findViewById<SeekBar>(seekId)
        seek.progress = prefs.getInt(key, 100)
        player.setVolume(role, seek.progress / 100f)
        seek.setOnSeekBarChangeListener(object : SimpleSeekListener() {
            override fun onProgressChanged(seekBar: SeekBar, progress: Int, fromUser: Boolean) {
                player.setVolume(role, progress / 100f)
                prefs.edit().putInt(key, progress).apply()
            }
        })
    }

    private fun setupOffset() {
        setOffset(prefs.getInt(KEY_OFFSET, 0))
        offsetSeek.setOnSeekBarChangeListener(object : SimpleSeekListener() {
            override fun onProgressChanged(seekBar: SeekBar, progress: Int, fromUser: Boolean) {
                if (fromUser) setOffset(progress - OFFSET_RANGE_MS)
            }
        })
        findViewById<Button>(R.id.offsetMinus).setOnClickListener { setOffset(player.offsetMs - 10) }
        findViewById<Button>(R.id.offsetPlus).setOnClickListener { setOffset(player.offsetMs + 10) }
        findViewById<Button>(R.id.offsetZero).setOnClickListener { setOffset(0) }
    }

    private fun setOffset(value: Int) {
        val v = value.coerceIn(-OFFSET_RANGE_MS, OFFSET_RANGE_MS)
        player.offsetMs = v
        offsetSeek.progress = v + OFFSET_RANGE_MS
        offsetLabel.text = when {
            v > 0 -> "スピーカー（カラオケ）を ${v}ms 遅らせる"
            v < 0 -> "イヤホン（ボーカル）を ${-v}ms 遅らせる"
            else -> "補正なし（0ms）"
        }
        prefs.edit().putInt(KEY_OFFSET, v).apply()
        positionSeek.max = player.durationMs()
    }

    // ---- 再生操作 ----

    private fun setupTransport() {
        playButton.setOnClickListener {
            if (player.isPlaying) {
                player.pause()
            } else if (!player.hasSource(Role.VOCAL) && !player.hasSource(Role.KARAOKE)) {
                Toast.makeText(this, "音源ファイルを選んでください", Toast.LENGTH_SHORT).show()
            } else {
                player.play()
            }
            updatePlayButton()
        }
        findViewById<Button>(R.id.stopButton).setOnClickListener {
            player.stop()
            updatePlayButton()
            updatePosition()
        }
        positionSeek.setOnSeekBarChangeListener(object : SimpleSeekListener() {
            override fun onProgressChanged(seekBar: SeekBar, progress: Int, fromUser: Boolean) {
                if (fromUser) positionText.text = "${formatTime(progress)} / ${formatTime(seekBar.max)}"
            }

            override fun onStartTrackingTouch(seekBar: SeekBar) {
                userSeeking = true
            }

            override fun onStopTrackingTouch(seekBar: SeekBar) {
                userSeeking = false
                player.seekTo(seekBar.progress)
            }
        })
    }

    private fun updatePlayButton() {
        playButton.setText(if (player.isPlaying) R.string.pause else R.string.play)
        if (player.isPlaying) {
            window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        } else {
            window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        }
    }

    private fun updatePosition() {
        if (userSeeking) return
        val duration = player.durationMs()
        val position = player.positionMs().coerceIn(0, duration)
        positionSeek.max = duration
        positionSeek.progress = position
        positionText.text = "${formatTime(position)} / ${formatTime(duration)}"
    }

    private fun formatTime(ms: Int): String {
        val s = ms / 1000
        return "%d:%02d".format(s / 60, s % 60)
    }

    private open class SimpleSeekListener : SeekBar.OnSeekBarChangeListener {
        override fun onProgressChanged(seekBar: SeekBar, progress: Int, fromUser: Boolean) {}
        override fun onStartTrackingTouch(seekBar: SeekBar) {}
        override fun onStopTrackingTouch(seekBar: SeekBar) {}
    }

    companion object {
        private const val REQUEST_VOCAL = 1
        private const val REQUEST_KARAOKE = 2
        private const val OFFSET_RANGE_MS = 500
        private const val KEY_VOCAL_URI = "vocal_uri"
        private const val KEY_KARAOKE_URI = "karaoke_uri"
        private const val KEY_VOCAL_DEVICE = "vocal_device"
        private const val KEY_KARAOKE_DEVICE = "karaoke_device"
        private const val KEY_OFFSET = "offset_ms"
    }
}
