package io.github.sakurazakiharuo.dualkaraoke

import android.app.Activity
import android.app.AlertDialog
import android.content.Intent
import android.content.SharedPreferences
import android.content.res.ColorStateList
import android.media.AudioDeviceCallback
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.OpenableColumns
import android.text.TextUtils
import android.view.View
import android.view.WindowInsets
import android.view.WindowManager
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.SeekBar
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import io.github.sakurazakiharuo.dualkaraoke.DualPlayer.Role

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
    private val currentUris = mutableMapOf<Role, Uri?>()
    private val presetButtons = ArrayList<Button>()

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
        applySystemBarInsets()

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
        setupOutputMode()
        setupTransport()
        setupPresets()

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

    /**
     * Android 15以降は画面が端まで広がるので、ナビゲーションバーに操作ボタンが隠れないよう余白をとる。
     * 上端はアクションバーが状態バーの分をよけてくれる。
     */
    private fun applySystemBarInsets() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.VANILLA_ICE_CREAM) return
        val root = findViewById<View>(R.id.root)
        val base = root.paddingBottom
        root.setOnApplyWindowInsetsListener { v, insets ->
            val bars = insets.getInsets(WindowInsets.Type.systemBars() or WindowInsets.Type.displayCutout())
            v.setPadding(bars.left, v.paddingTop, bars.right, base + bars.bottom)
            insets
        }
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
            REQUEST_VOCAL -> loadSource(Role.VOCAL, uri)
            REQUEST_KARAOKE -> loadSource(Role.KARAOKE, uri)
        }
        setSelectedPreset(0)
    }

    private fun restoreSource(role: Role, key: String) {
        val uri = prefs.getString(key, null)?.let { Uri.parse(it) } ?: return
        if (isReadable(uri)) loadSource(role, uri) else prefs.edit().remove(key).apply()
    }

    private fun isReadable(uri: Uri) =
        contentResolver.persistedUriPermissions.any { it.uri == uri && it.isReadPermission }

    private fun loadSource(role: Role, uri: Uri?) {
        val ok = uri != null && player.setSource(role, uri)
        if (!ok) player.setSource(role, null)
        val loaded = if (ok) uri else null
        currentUris[role] = loaded
        val label = if (loaded != null) displayName(loaded) else getString(R.string.no_file)
        (if (role == Role.VOCAL) vocalFileName else karaokeFileName).text = label
        val key = if (role == Role.VOCAL) KEY_VOCAL_URI else KEY_KARAOKE_URI
        prefs.edit().apply { if (loaded != null) putString(key, loaded.toString()) else remove(key) }.apply()
        positionSeek.max = player.durationMs()
        updatePlayButton()
    }

    private fun displayName(uri: Uri): String {
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use {
            if (it.moveToFirst()) return it.getString(0)
        }
        return uri.lastPathSegment ?: uri.toString()
    }

    // ---- 曲ボタン ----

    private fun setupPresets() {
        val grid = findViewById<LinearLayout>(R.id.presetGrid)
        val height = (72 * resources.displayMetrics.density).toInt()
        for (row in 0 until PRESET_COUNT / PRESET_COLUMNS) {
            val rowLayout = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            for (col in 0 until PRESET_COLUMNS) {
                val slot = row * PRESET_COLUMNS + col + 1
                val button = Button(this).apply {
                    isAllCaps = false
                    maxLines = 2
                    ellipsize = TextUtils.TruncateAt.END
                    textSize = 13f
                    setOnClickListener { selectPreset(slot) }
                    setOnLongClickListener {
                        showPresetMenu(slot)
                        true
                    }
                }
                rowLayout.addView(button, LinearLayout.LayoutParams(0, height, 1f))
                presetButtons.add(button)
            }
            grid.addView(rowLayout)
        }
        updatePresetButtons()
    }

    private fun presetKey(slot: Int, field: String) = "preset_${slot}_$field"

    private fun presetUri(slot: Int, role: Role) =
        prefs.getString(presetKey(slot, if (role == Role.VOCAL) "vocal" else "karaoke"), null)?.let { Uri.parse(it) }

    private fun isPresetRegistered(slot: Int) =
        presetUri(slot, Role.VOCAL) != null || presetUri(slot, Role.KARAOKE) != null

    private fun updatePresetButtons() {
        val selected = prefs.getInt(KEY_SELECTED_PRESET, 0)
        presetButtons.forEachIndexed { i, button ->
            val slot = i + 1
            val name = if (isPresetRegistered(slot)) {
                prefs.getString(presetKey(slot, "name"), null) ?: ""
            } else {
                getString(R.string.preset_empty)
            }
            button.text = "$slot\n$name"
            button.backgroundTintList =
                if (slot == selected) ColorStateList.valueOf(SELECTED_PRESET_COLOR) else null
        }
    }

    private fun setSelectedPreset(slot: Int) {
        prefs.edit().putInt(KEY_SELECTED_PRESET, slot).apply()
        updatePresetButtons()
    }

    private fun selectPreset(slot: Int) {
        if (!isPresetRegistered(slot)) {
            Toast.makeText(this, "長押しすると、今選んでいる曲をボタン${slot}に登録できます", Toast.LENGTH_LONG).show()
            return
        }
        player.stop()
        var missing = false
        for (role in Role.values()) {
            val uri = presetUri(slot, role)?.takeIf { isReadable(it) }
            if (uri == null && presetUri(slot, role) != null) missing = true
            loadSource(role, uri)
        }
        if (missing) {
            Toast.makeText(this, "見つからないファイルがあります。登録し直してください", Toast.LENGTH_LONG).show()
        }
        setSelectedPreset(slot)
        updatePosition()
    }

    private fun showPresetMenu(slot: Int) {
        val registered = isPresetRegistered(slot)
        val items = mutableListOf("今選んでいる曲をボタン${slot}に登録")
        if (registered) items += listOf("名前を変更", "登録を消す")
        AlertDialog.Builder(this)
            .setTitle("ボタン $slot")
            .setItems(items.toTypedArray()) { _, which ->
                when (which) {
                    0 -> registerPreset(slot)
                    1 -> renamePreset(slot)
                    2 -> clearPreset(slot)
                }
            }
            .show()
    }

    private fun registerPreset(slot: Int) {
        val vocal = currentUris[Role.VOCAL]
        val karaoke = currentUris[Role.KARAOKE]
        if (vocal == null && karaoke == null) {
            Toast.makeText(this, "先に歌手用・スピーカー用の音源を選んでください", Toast.LENGTH_LONG).show()
            return
        }
        val name = displayName(vocal ?: karaoke!!).substringBeforeLast('.')
        prefs.edit()
            .putString(presetKey(slot, "vocal"), vocal?.toString())
            .putString(presetKey(slot, "karaoke"), karaoke?.toString())
            .putString(presetKey(slot, "name"), name)
            .apply()
        setSelectedPreset(slot)
        Toast.makeText(this, "ボタン${slot}に「$name」を登録しました", Toast.LENGTH_SHORT).show()
    }

    private fun renamePreset(slot: Int) {
        val input = EditText(this).apply {
            setText(prefs.getString(presetKey(slot, "name"), ""))
            setSingleLine()
            selectAll()
        }
        AlertDialog.Builder(this)
            .setTitle("ボタン $slot の名前")
            .setView(input)
            .setPositiveButton("OK") { _, _ ->
                prefs.edit().putString(presetKey(slot, "name"), input.text.toString().trim()).apply()
                updatePresetButtons()
            }
            .setNegativeButton("キャンセル", null)
            .show()
    }

    private fun clearPreset(slot: Int) {
        AlertDialog.Builder(this)
            .setMessage("ボタン${slot}の登録を消しますか？（音源ファイル自体は消えません）")
            .setPositiveButton("消す") { _, _ ->
                prefs.edit()
                    .remove(presetKey(slot, "vocal"))
                    .remove(presetKey(slot, "karaoke"))
                    .remove(presetKey(slot, "name"))
                    .apply()
                if (prefs.getInt(KEY_SELECTED_PRESET, 0) == slot) setSelectedPreset(0) else updatePresetButtons()
            }
            .setNegativeButton("キャンセル", null)
            .show()
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

    private fun setupOutputMode() {
        val spinner = findViewById<Spinner>(R.id.outputModeSpinner)
        spinner.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, resources.getStringArray(R.array.output_modes)
        )
        val saved = prefs.getInt(KEY_OUTPUT_MODE, DEFAULT_OUTPUT_MODE)
        player.outputMode = saved
        spinner.setSelection(saved)
        spinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                player.outputMode = position
                prefs.edit().putInt(KEY_OUTPUT_MODE, position).apply()
            }

            override fun onNothingSelected(parent: AdapterView<*>?) {}
        }
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
        private const val KEY_SELECTED_PRESET = "selected_preset"
        private const val KEY_OUTPUT_MODE = "output_mode"
        private const val DEFAULT_OUTPUT_MODE = 1
        private const val PRESET_COUNT = 12
        private const val PRESET_COLUMNS = 4
        private const val SELECTED_PRESET_COLOR = 0xFFFFB74D.toInt()
    }
}
