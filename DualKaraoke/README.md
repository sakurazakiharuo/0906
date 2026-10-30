# デュアルカラオケ（Androidアプリ）

1台のAndroidスマホから、**2つの音源を別々の出力機器へ同時に**流すアプリです。

| 出力 | 音源 | 聞く人 |
|---|---|---|
| 🎧 Bluetoothイヤホン | ボーカル入り（ガイド） | 歌う人だけ |
| 🔊 USB／有線／本体スピーカー | カラオケ（伴奏のみ） | 会場のみんな |

## 重要な制約

Androidは通常、**Bluetoothで音楽（A2DP）を流せるのは同時に1台まで**です。
公開APIで2台のBluetooth機器に別々の音を送ることはできません。おすすめの構成は次のとおりです。

- イヤホン：Bluetooth
- スピーカー：USB-C接続のスピーカー、USB-C→3.5mm変換アダプタ＋有線スピーカー、または本体スピーカー

## 使い方

1. 「歌手用」でボーカル入り音源を、「スピーカー用」でカラオケ音源を選ぶ
2. それぞれの「出力先」を選ぶ（初回は自動でBluetooth／USBを選びます）
3. ▶ 再生
4. **ずれ補正**：Bluetoothイヤホンは0.1〜0.3秒ほど遅れて聞こえます。
   イヤホンとスピーカーを同時に聞きながら、スライダーで「スピーカーを◯ms遅らせる」を調整してください。
   値は保存されます。

再生中は約0.5秒ごとに2つのプレーヤーの位置を比べ、80ms以上ずれたらカラオケ側を合わせ直します。

## ビルド

- Android Studioで `DualKaraoke/` フォルダを開いて実行
- またはGitHubにpushすると、GitHub Actions（`.github/workflows/android.yml`）が
  デバッグAPKをビルドします。Actionsの実行結果の「Artifacts」から `DualKaraoke-debug-apk` をダウンロードして
  スマホにインストールできます（提供元不明のアプリのインストールを許可する必要があります）。

対応：Android 8.0（API 26）以上

## 仕組み

`MediaPlayer` を2つ使い、`MediaPlayer.setPreferredDevice(AudioDeviceInfo)` で
それぞれの出力機器を指定しています（`DualPlayer.kt`）。
