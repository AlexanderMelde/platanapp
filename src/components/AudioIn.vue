<script setup lang="ts">
import { ref, nextTick } from 'vue'

const mediaRecorder = ref<MediaRecorder | null>(null)
const audioChunks = ref<Blob[]>([])
const isRecording = ref(false)
const audioUrl = ref<string | null>(null)
const audioFile = ref<string>("")
const progress = ref<number>(0)
const countdownRef = ref<number>(10)
const timerRef = ref<NodeJS.Timeout | null>(null)

console.log("Mode:", import.meta.env.MODE)
/*
  Vite automatically sets import.meta.env.MODE based on the mode you use to run or build your project.
  - When you run `vite` or `vite dev`, MODE is 'development'.
  - When you run `vite build`, MODE is 'production'.
  - You can specify a custom mode with `vite --mode <mode>`.
  - To set environment variables for a specific mode, create `.env`, `.env.development`, `.env.production`, or `.env.<custom>` files in your project root.
  - You cannot set import.meta.env.MODE directly in code; it is determined by how you run Vite.
*/
const uploadUrl = 'https://llama.ok-lab-karlsruhe.de/platane/php/audioRx.php'



import { defineEmits } from 'vue'
const emit = defineEmits<{
  (e: 'upload-result', result: { success: boolean, data?: any, error?: any }): void
  (e: 'reset'): void
}>()

async function startRecording() {
  if (isRecording.value) return
  emit('reset')
  await nextTick()
  const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
  mediaRecorder.value = new MediaRecorder(stream)
  audioChunks.value = []
  mediaRecorder.value.ondataavailable = (e) => {
    if (e.data.size > 0) audioChunks.value.push(e.data)
  }
  mediaRecorder.value.onstop = () => {
    const blob = new Blob(audioChunks.value, { type: 'audio/webm' })
    if (!blob.size) {
      console.error('No audio data recorded.')
      return
    }
    audioUrl.value = URL.createObjectURL(blob)
    const reader = new FileReader()
    reader.onloadend = () => {
      // const base64Audio = reader.result
      if (!reader.result) {
        console.error('Failed to read audio data.');
        emit('upload-result', { success: false, error: 'Failed to read audio data.' });
        return;
      }
      const base64Audio = String(reader.result).split(',')[1]  // Convert to string before split
      fetch(uploadUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          filename: 'recording.webm',
          audio: base64Audio
        })
      })
        .then(response => response.json())
        .then(data => {
          // handle response if needed
          console.log('Upload successful:', data)
          audioFile.value = data.filename
          emit('upload-result', { success: true, data: { filename: data.filename } })
        })
        .catch(error => {
          console.error('Upload failed:', error)
        })
    }
    reader.readAsDataURL(blob)
  }
  mediaRecorder.value.start()
  isRecording.value = true
  audioFile.value = ''
  let countdown = 10
  const timer = setInterval(() => {
    countdown--
    progress.value = ((10 - countdown) / 10) * 100
    if (countdown <= 0) {
      clearInterval(timer)
      stopRecording()
    }
  }, 1000)
  progress.value = 0
  countdownRef.value = countdown
  timerRef.value = timer
}

function stopRecording() {
  if (mediaRecorder.value && isRecording.value) {
    mediaRecorder.value.stop()
    isRecording.value = false
  }
}

</script>

<template>
  <div>
    <button @click="startRecording" :disabled="isRecording">Start Recording</button>
    <button @click="stopRecording" :disabled="!isRecording">Stop Recording</button>
    <div v-if="isRecording">
      <p>Recording... {{ countdownRef }} seconds left</p>
      <progress :value="progress" max="100"></progress>
    </div>
    <div v-if="audioUrl">
      <audio :src="audioUrl" controls></audio>
    </div>
    <p> File: {{ audioFile }} </p>
  </div>

</template>

<style scoped></style>
