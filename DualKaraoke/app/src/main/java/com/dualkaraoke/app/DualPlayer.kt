package com.dualkaraoke.app

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioDeviceInfo
import android.media.MediaPlayer
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import kotlin.math.abs
import kotlin.math.max

/**
 * 2つの音源を、それぞれ別の出力機器へ同時に再生するプレーヤー。
 *
 * 再生位置は共通の「タイムライン」で管理し、各トラックは
 * (タイムライン位置 - lagMs) の位置を再生する。lagMs はずれ補正で決まる。
 * ボーカル側をマスターとし、カラオケ側がずれたら追従させる。
 */
class DualPlayer(private val context: Context) {

    enum class Role { VOCAL, KARAOKE }

    private class Track {
        var uri: Uri? = null
        var device: AudioDeviceInfo? = null
        var volume = 1f
        var player: MediaPlayer? = null
        var lagMs = 0
        var pendingStart: Runnable? = null
        var lastSeekAt = 0L
    }

    private val handler = Handler(Looper.getMainLooper())
    private val tracks = linkedMapOf(Role.VOCAL to Track(), Role.KARAOKE to Track())

    var onCompletion: (() -> Unit)? = null
    var onError: ((String) -> Unit)? = null

    var isPlaying = false
        private set

    private var pausedTimelineMs = 0
    private var playStartTimelineMs = 0
    private var playStartRealtime = 0L

    /**
     * 正の値: カラオケ（スピーカー）を遅らせる。
     * 負の値: ボーカル（イヤホン）を遅らせる。
     */
    var offsetMs = 0
        set(value) {
            field = value
            track(Role.VOCAL).lagMs = max(0, -value)
            track(Role.KARAOKE).lagMs = max(0, value)
        }

    private fun track(role: Role) = tracks.getValue(role)

    private fun activeTracks() = tracks.values.filter { it.player != null }

    /** ボーカルがあればボーカル、なければカラオケを基準にする。 */
    private fun masterTrack() = activeTracks().firstOrNull()

    fun hasSource(role: Role) = track(role).uri != null

    /** 音源を設定して準備する。失敗したら false。 */
    fun setSource(role: Role, uri: Uri?): Boolean {
        if (isPlaying) pause()
        val t = track(role)
        t.player?.release()
        t.player = null
        t.uri = uri
        pausedTimelineMs = 0
        if (uri == null) return true
        return try {
            t.player = MediaPlayer().apply {
                setAudioAttributes(
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_MEDIA)
                        .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                        .build()
                )
                setDataSource(context, uri)
                setPreferredDevice(t.device)
                setVolume(t.volume, t.volume)
                setOnCompletionListener { checkFinished() }
                prepare()
            }
            true
        } catch (e: Exception) {
            t.player?.release()
            t.player = null
            t.uri = null
            onError?.invoke("音源を開けませんでした: ${e.message}")
            false
        }
    }

    fun setDevice(role: Role, device: AudioDeviceInfo?) {
        val t = track(role)
        t.device = device
        t.player?.setPreferredDevice(device)
    }

    fun setVolume(role: Role, volume: Float) {
        val t = track(role)
        t.volume = volume
        t.player?.setVolume(volume, volume)
    }

    fun durationMs(): Int =
        activeTracks().maxOfOrNull { it.player!!.duration + it.lagMs } ?: 0

    fun positionMs(): Int {
        if (!isPlaying) return pausedTimelineMs
        val master = masterTrack()
        val mp = master?.player
        if (master != null && mp != null && master.pendingStart == null && mp.isPlaying) {
            return mp.currentPosition + master.lagMs
        }
        return playStartTimelineMs + (SystemClock.elapsedRealtime() - playStartRealtime).toInt()
    }

    fun play() {
        if (isPlaying || activeTracks().isEmpty()) return
        if (pausedTimelineMs >= durationMs()) pausedTimelineMs = 0
        startAt(pausedTimelineMs)
    }

    fun pause() {
        if (!isPlaying) return
        pausedTimelineMs = positionMs()
        cancelPending()
        activeTracks().forEach { t -> t.player?.let { if (it.isPlaying) it.pause() } }
        isPlaying = false
        handler.removeCallbacks(syncLoop)
    }

    fun stop() {
        pause()
        pausedTimelineMs = 0
        activeTracks().forEach { it.player?.seekTo(0) }
    }

    fun seekTo(timelineMs: Int) {
        if (isPlaying) startAt(timelineMs) else pausedTimelineMs = timelineMs
    }

    fun release() {
        cancelPending()
        handler.removeCallbacks(syncLoop)
        tracks.values.forEach {
            it.player?.release()
            it.player = null
        }
        isPlaying = false
    }

    private fun startAt(timelineMs: Int) {
        cancelPending()
        isPlaying = true
        playStartTimelineMs = timelineMs
        playStartRealtime = SystemClock.elapsedRealtime()
        val now = playStartRealtime
        for (t in activeTracks()) {
            val p = t.player!!
            val pos = timelineMs - t.lagMs
            if (pos >= p.duration) {
                if (p.isPlaying) p.pause()
                continue
            }
            t.lastSeekAt = now
            if (pos >= 0) {
                p.seekTo(pos.toLong(), MediaPlayer.SEEK_CLOSEST)
                if (!p.isPlaying) p.start()
            } else {
                // このトラックはまだ始まっていない時間帯なので、遅れて開始する
                if (p.isPlaying) p.pause()
                p.seekTo(0L, MediaPlayer.SEEK_CLOSEST)
                val r = Runnable {
                    t.pendingStart = null
                    if (isPlaying) {
                        t.lastSeekAt = SystemClock.elapsedRealtime()
                        p.start()
                    }
                }
                t.pendingStart = r
                handler.postDelayed(r, (-pos).toLong())
            }
        }
        handler.removeCallbacks(syncLoop)
        handler.postDelayed(syncLoop, SYNC_INTERVAL_MS)
    }

    private fun cancelPending() {
        tracks.values.forEach { t ->
            t.pendingStart?.let { handler.removeCallbacks(it) }
            t.pendingStart = null
        }
    }

    private val syncLoop = object : Runnable {
        override fun run() {
            if (!isPlaying) return
            correctDrift()
            handler.postDelayed(this, SYNC_INTERVAL_MS)
        }
    }

    /** マスター以外のトラックが一定以上ずれていたら位置を合わせ直す。 */
    private fun correctDrift() {
        val master = masterTrack() ?: return
        val mp = master.player ?: return
        if (master.pendingStart != null || !mp.isPlaying) return
        val now = SystemClock.elapsedRealtime()
        if (now - master.lastSeekAt < SETTLE_MS) return
        val timeline = mp.currentPosition + master.lagMs
        for (t in activeTracks()) {
            if (t === master || t.pendingStart != null) continue
            val p = t.player ?: continue
            if (!p.isPlaying || now - t.lastSeekAt < SETTLE_MS) continue
            val expected = timeline - t.lagMs
            if (expected < 0 || expected >= p.duration) continue
            if (abs(p.currentPosition - expected) > DRIFT_THRESHOLD_MS) {
                p.seekTo(expected.toLong(), MediaPlayer.SEEK_CLOSEST)
                t.lastSeekAt = now
            }
        }
    }

    private fun checkFinished() {
        if (!isPlaying) return
        val stillPlaying = activeTracks().any { it.pendingStart != null || it.player?.isPlaying == true }
        if (!stillPlaying) {
            isPlaying = false
            handler.removeCallbacks(syncLoop)
            pausedTimelineMs = 0
            onCompletion?.invoke()
        }
    }

    companion object {
        private const val SYNC_INTERVAL_MS = 500L
        private const val SETTLE_MS = 1500L
        private const val DRIFT_THRESHOLD_MS = 80
    }
}
