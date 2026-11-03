package com.dualkaraoke.app

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioDeviceInfo
import android.media.AudioFormat
import android.media.AudioTrack
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.Process
import java.nio.ByteBuffer
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import kotlin.math.max
import kotlin.math.min

/**
 * 2つの音源を、それぞれ別の出力機器へ同時に再生するプレーヤー。
 *
 * 各音源を MediaCodec でデコードし、出力機器ごとの AudioTrack へ流す。
 * 両方のバッファを先に満たしてから同時に再生を始め、再生中は位置を飛ばさない。
 * ずれ補正の変更は、無音の挿入または音の読み飛ばしで再生を止めずに反映する。
 *
 * 再生位置は共通の「タイムライン」で管理し、各トラックは
 * (タイムライン位置 - lagMs) の位置を再生する。
 */
class DualPlayer(private val context: Context) {

    enum class Role { VOCAL, KARAOKE }

    private class Track {
        var uri: Uri? = null
        var durationMs = 0
        @Volatile var device: AudioDeviceInfo? = null
        @Volatile var volume = 1f
        var lagMs = 0
        var worker: Worker? = null
    }

    private val handler = Handler(Looper.getMainLooper())
    private val tracks = linkedMapOf(Role.VOCAL to Track(), Role.KARAOKE to Track())

    var onCompletion: (() -> Unit)? = null
    var onError: ((String) -> Unit)? = null

    var isPlaying = false
        private set

    /** 再生していないときのタイムライン位置。再生開始時はその開始位置。 */
    private var pausedTimelineMs = 0
    /** 両方のバッファが満ちて実際に音が出始めたか。 */
    private var started = false
    private var generation = 0

    /**
     * 正の値: カラオケ（スピーカー）を遅らせる。
     * 負の値: ボーカル（イヤホン）を遅らせる。
     */
    var offsetMs = 0
        set(value) {
            field = value
            for ((role, lag) in listOf(Role.VOCAL to max(0, -value), Role.KARAOKE to max(0, value))) {
                val t = track(role)
                t.worker?.shift(lag - t.lagMs)
                t.lagMs = lag
            }
        }

    private fun track(role: Role) = tracks.getValue(role)

    private fun activeTracks() = tracks.values.filter { it.uri != null }

    private fun currentWorkers() = tracks.values.mapNotNull { it.worker }

    fun hasSource(role: Role) = track(role).uri != null

    /** 音源を設定する。読めなければ false。 */
    fun setSource(role: Role, uri: Uri?): Boolean {
        stopWorkers()
        isPlaying = false
        pausedTimelineMs = 0
        val t = track(role)
        t.uri = null
        t.durationMs = 0
        if (uri == null) return true
        return try {
            t.durationMs = readDurationMs(uri)
            t.uri = uri
            true
        } catch (e: Exception) {
            onError?.invoke("音源を開けませんでした: ${e.message}")
            false
        }
    }

    private fun readDurationMs(uri: Uri): Int {
        val extractor = MediaExtractor()
        try {
            extractor.setDataSource(context, uri, null)
            val format = (0 until extractor.trackCount)
                .map { extractor.getTrackFormat(it) }
                .firstOrNull { it.getString(MediaFormat.KEY_MIME)?.startsWith("audio/") == true }
                ?: throw IllegalArgumentException("音声が含まれていません")
            if (format.containsKey(MediaFormat.KEY_DURATION)) {
                return (format.getLong(MediaFormat.KEY_DURATION) / 1000).toInt()
            }
        } finally {
            extractor.release()
        }
        val retriever = MediaMetadataRetriever()
        try {
            retriever.setDataSource(context, uri)
            return retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toInt() ?: 0
        } finally {
            retriever.release()
        }
    }

    fun setDevice(role: Role, device: AudioDeviceInfo?) {
        val t = track(role)
        t.device = device
        t.worker?.audioTrack?.let { runCatching { it.setPreferredDevice(device) } }
    }

    fun setVolume(role: Role, volume: Float) {
        val t = track(role)
        t.volume = volume
        t.worker?.audioTrack?.let { runCatching { it.setVolume(volume) } }
    }

    fun durationMs(): Int = activeTracks().maxOfOrNull { it.durationMs + it.lagMs } ?: 0

    fun positionMs(): Int {
        if (!started) return pausedTimelineMs
        for (t in activeTracks()) {
            val pos = t.worker?.sourcePositionMs() ?: continue
            return pos + t.lagMs
        }
        return pausedTimelineMs
    }

    fun play() {
        if (isPlaying || activeTracks().isEmpty()) return
        if (started && currentWorkers().isNotEmpty()) {
            // 一時停止からの再開: バッファはそのままなので両方同時に再開するだけ
            isPlaying = true
            currentWorkers().forEach { w -> w.audioTrack?.let { runCatching { it.play() } } }
            return
        }
        if (pausedTimelineMs >= durationMs()) pausedTimelineMs = 0
        startAt(pausedTimelineMs)
    }

    fun pause() {
        if (!isPlaying) return
        if (started) {
            pausedTimelineMs = positionMs()
            currentWorkers().forEach { w -> w.audioTrack?.let { runCatching { it.pause() } } }
        } else {
            stopWorkers()
        }
        isPlaying = false
    }

    fun stop() {
        stopWorkers()
        isPlaying = false
        pausedTimelineMs = 0
    }

    fun seekTo(timelineMs: Int) {
        if (isPlaying) {
            startAt(timelineMs)
        } else {
            stopWorkers()
            pausedTimelineMs = timelineMs
        }
    }

    fun release() {
        stopWorkers()
        isPlaying = false
    }

    private fun startAt(timelineMs: Int) {
        stopWorkers()
        val gen = generation
        isPlaying = true
        pausedTimelineMs = timelineMs
        val list = activeTracks().filter { timelineMs - it.lagMs < it.durationMs }
        if (list.isEmpty()) {
            isPlaying = false
            return
        }
        val ready = CountDownLatch(list.size)
        for (t in list) {
            val w = Worker(t.uri!!, t, timelineMs - t.lagMs, ready) { handler.post { onWorkerFinished(gen) } }
            t.worker = w
            w.start()
        }
        // 両方のバッファが満ちるのを待ってから、同時に再生を始める
        Thread {
            ready.await(5, TimeUnit.SECONDS)
            handler.post {
                if (gen != generation || !isPlaying) return@post
                started = true
                currentWorkers().forEach { w -> w.audioTrack?.let { runCatching { it.play() } } }
            }
        }.start()
    }

    private fun stopWorkers() {
        generation++
        started = false
        for (t in tracks.values) {
            t.worker?.quit()
            t.worker = null
        }
    }

    private fun onWorkerFinished(gen: Int) {
        if (gen != generation) return
        if (currentWorkers().all { it.finished }) {
            stopWorkers()
            isPlaying = false
            pausedTimelineMs = 0
            onCompletion?.invoke()
        }
    }

    /** 1つの音源をデコードして AudioTrack へ書き込むスレッド。 */
    private inner class Worker(
        private val uri: Uri,
        private val track: Track,
        /** 再生を始める音源上の位置。負の値なら、その分だけ先に無音を流す。 */
        private val startSourceMs: Int,
        private val ready: CountDownLatch,
        private val onFinished: () -> Unit,
    ) : Thread("DualPlayer-worker") {

        @Volatile private var stopped = false
        @Volatile var audioTrack: AudioTrack? = null
            private set
        @Volatile var finished = false
            private set

        @Volatile private var sampleRate = 0
        private var frameBytes = 0
        private var framesWritten = 0L
        private var readySent = false

        /** ずれ補正の変更でまだ反映していない分(ms)。正なら無音を挿入、負なら読み飛ばす。 */
        private val pendingShiftMs = AtomicInteger(0)
        private var framesToDrop = 0L
        /** 挿入した無音 − 読み飛ばした分（フレーム数）。再生位置の計算に使う。 */
        @Volatile private var shiftedFrames = 0L

        private var pcm = ByteArray(0)
        private val silence = ByteArray(8192)

        fun shift(deltaMs: Int) {
            pendingShiftMs.addAndGet(deltaMs)
        }

        fun quit() {
            stopped = true
            audioTrack?.let { runCatching { it.pause() } }
        }

        /** 音源上の現在の再生位置(ms)。 */
        fun sourcePositionMs(): Int? {
            val at = audioTrack ?: return null
            val sr = sampleRate
            if (sr == 0) return null
            val played = runCatching { at.playbackHeadPosition.toLong() and 0xFFFFFFFFL }.getOrNull() ?: return null
            return startSourceMs + ((played - shiftedFrames) * 1000 / sr).toInt()
        }

        override fun run() {
            Process.setThreadPriority(Process.THREAD_PRIORITY_AUDIO)
            var extractor: MediaExtractor? = null
            var codec: MediaCodec? = null
            try {
                extractor = MediaExtractor().apply { setDataSource(context, uri, null) }
                val index = (0 until extractor.trackCount).first {
                    extractor.getTrackFormat(it).getString(MediaFormat.KEY_MIME)?.startsWith("audio/") == true
                }
                extractor.selectTrack(index)
                val format = extractor.getTrackFormat(index)
                val startUs = max(0, startSourceMs) * 1000L
                if (startUs > 0) extractor.seekTo(startUs, MediaExtractor.SEEK_TO_PREVIOUS_SYNC)

                codec = MediaCodec.createDecoderByType(format.getString(MediaFormat.KEY_MIME)!!)
                codec.configure(format, null, null, 0)
                codec.start()

                val info = MediaCodec.BufferInfo()
                var outFormat: MediaFormat? = null
                var inputDone = false
                while (!stopped) {
                    if (!inputDone) {
                        val inIndex = codec.dequeueInputBuffer(10_000)
                        if (inIndex >= 0) {
                            val size = extractor.readSampleData(codec.getInputBuffer(inIndex)!!, 0)
                            if (size < 0) {
                                codec.queueInputBuffer(inIndex, 0, 0, 0, MediaCodec.BUFFER_FLAG_END_OF_STREAM)
                                inputDone = true
                            } else {
                                codec.queueInputBuffer(inIndex, 0, size, extractor.sampleTime, 0)
                                extractor.advance()
                            }
                        }
                    }
                    val outIndex = codec.dequeueOutputBuffer(info, 10_000)
                    if (outIndex == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED) {
                        outFormat = codec.outputFormat
                    } else if (outIndex >= 0) {
                        if (info.size > 0) {
                            val buf = codec.getOutputBuffer(outIndex)!!
                            if (pcm.size < info.size) pcm = ByteArray(info.size)
                            buf.position(info.offset)
                            buf.get(pcm, 0, info.size)
                            codec.releaseOutputBuffer(outIndex, false)
                            handlePcm(info.size, info.presentationTimeUs, startUs, outFormat ?: codec.outputFormat)
                        } else {
                            codec.releaseOutputBuffer(outIndex, false)
                        }
                        if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) break
                    }
                }
                markReady()
                if (!stopped) drain()
            } catch (e: Exception) {
                if (!stopped) handler.post { onError?.invoke("再生エラー: ${e.message}") }
            } finally {
                markReady()
                runCatching { codec?.stop() }
                runCatching { codec?.release() }
                runCatching { extractor?.release() }
                audioTrack?.let {
                    runCatching { it.pause() }
                    runCatching { it.flush() }
                    runCatching { it.release() }
                }
                finished = true
                if (!stopped) onFinished()
            }
        }

        private fun handlePcm(size: Int, ptsUs: Long, startUs: Long, format: MediaFormat) {
            if (audioTrack == null) {
                audioTrack = createAudioTrack(format)
                writeSilence(max(0, -startSourceMs).toLong() * sampleRate / 1000)
            }
            applyShift()
            var offset = 0
            // シークは直前の区切りまでしか戻らないので、開始位置より前の音を捨てる
            if (ptsUs < startUs) {
                val skip = (startUs - ptsUs) * sampleRate / 1_000_000
                offset = min(size.toLong(), skip * frameBytes).toInt()
            }
            if (framesToDrop > 0) {
                val drop = min(framesToDrop, ((size - offset) / frameBytes).toLong())
                framesToDrop -= drop
                shiftedFrames -= drop
                offset += (drop * frameBytes).toInt()
            }
            writeFully(pcm, offset, size - offset)
        }

        private fun applyShift() {
            val ms = pendingShiftMs.getAndSet(0)
            if (ms == 0) return
            val frames = ms.toLong() * sampleRate / 1000
            if (frames > 0) {
                shiftedFrames += frames
                writeSilence(frames)
            } else {
                framesToDrop -= frames
            }
        }

        private fun createAudioTrack(format: MediaFormat): AudioTrack {
            sampleRate = format.getInteger(MediaFormat.KEY_SAMPLE_RATE)
            val channels = format.getInteger(MediaFormat.KEY_CHANNEL_COUNT)
            val encoding = if (format.containsKey(MediaFormat.KEY_PCM_ENCODING)) {
                format.getInteger(MediaFormat.KEY_PCM_ENCODING)
            } else {
                AudioFormat.ENCODING_PCM_16BIT
            }
            frameBytes = channels * when (encoding) {
                AudioFormat.ENCODING_PCM_FLOAT -> 4
                AudioFormat.ENCODING_PCM_8BIT -> 1
                else -> 2
            }
            val audioFormat = AudioFormat.Builder()
                .setSampleRate(sampleRate)
                .setEncoding(encoding)
                .apply {
                    when (channels) {
                        1 -> setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                        2 -> setChannelMask(AudioFormat.CHANNEL_OUT_STEREO)
                        else -> setChannelIndexMask((1 shl channels) - 1)
                    }
                }
                .build()
            val bufferBytes = sampleRate * frameBytes * BUFFER_MS / 1000
            return AudioTrack.Builder()
                .setAudioAttributes(
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_MEDIA)
                        .setContentType(AudioAttributes.CONTENT_TYPE_MUSIC)
                        .build()
                )
                .setAudioFormat(audioFormat)
                .setBufferSizeInBytes(bufferBytes)
                .setTransferMode(AudioTrack.MODE_STREAM)
                .build()
                .apply {
                    setPreferredDevice(track.device)
                    setVolume(track.volume)
                }
        }

        private fun writeSilence(frames: Long) {
            var remaining = frames * frameBytes
            while (remaining > 0 && !stopped) {
                val n = min(remaining, silence.size.toLong()).toInt()
                writeFully(silence, 0, n)
                remaining -= n
            }
        }

        /** バッファに空きがなければ待ちながら、全部書き込む。 */
        private fun writeFully(data: ByteArray, offset: Int, length: Int) {
            if (length <= 0) return
            val at = audioTrack ?: return
            val buffer = ByteBuffer.wrap(data, offset, length)
            while (buffer.hasRemaining() && !stopped) {
                val n = at.write(buffer, buffer.remaining(), AudioTrack.WRITE_NON_BLOCKING)
                if (n < 0) throw IllegalStateException("AudioTrack write error $n")
                if (buffer.hasRemaining()) {
                    // バッファが満杯 = 再生開始の準備ができた
                    markReady()
                    sleep(5)
                }
            }
            framesWritten += length / frameBytes
        }

        private fun markReady() {
            if (!readySent) {
                readySent = true
                ready.countDown()
            }
        }

        /** 書き込んだ音を最後まで鳴らし切るのを待つ。 */
        private fun drain() {
            val at = audioTrack ?: return
            var lastHead = -1L
            var stalledSince = 0L
            while (!stopped) {
                val head = at.playbackHeadPosition.toLong() and 0xFFFFFFFFL
                if (head >= framesWritten) break
                if (at.playState == AudioTrack.PLAYSTATE_PLAYING) {
                    val now = System.currentTimeMillis()
                    if (head != lastHead) {
                        lastHead = head
                        stalledSince = now
                    } else if (now - stalledSince > 1000) {
                        break
                    }
                }
                sleep(20)
            }
        }
    }

    companion object {
        /** 出力ごとに先に貯めておく音の長さ。大きいほど途切れにくい。 */
        private const val BUFFER_MS = 400
    }
}
