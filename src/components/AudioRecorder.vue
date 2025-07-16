<template>
  <div class="audio-recorder">
    <div class="recorder-container">
      <button 
        @click="startRecording" 
        :disabled="isRecording"
        :class="['record-button', { 'recording': isRecording }]"
      >
        <div class="button-content">
          <div v-if="!isRecording" class="mic-icon">🎤</div>
          <div v-else class="recording-animation">
            <div class="wave wave-1"></div>
            <div class="wave wave-2"></div>
            <div class="wave wave-3"></div>
          </div>
          <span class="button-text">
            {{ isRecording ? 'Recording...' : 'Tap to Speak' }}
          </span>
        </div>
      </button>
      
      <button 
        v-if="isRecording"
        @click="stopRecording"
        class="stop-button"
      >
        <div class="stop-icon">⏹️</div>
        Stop
      </button>
    </div>
    
    <div v-if="isRecording" class="recording-info">
      <div class="countdown">{{ countdownRef }} seconds left</div>
      <div class="progress-container">
        <div class="progress-bar">
          <div class="progress-fill" :style="{ width: `${progress}%` }"></div>
        </div>
      </div>
    </div>
    
    <div v-if="audioUrl && !isRecording" class="playback-section">
      <div class="playback-info">
        <span class="file-icon">🎵</span>
        <span>Recording ready</span>
      </div>
      <audio :src="audioUrl" controls class="audio-player"></audio>
    </div>
  </div>
</template>

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
      if (!reader.result) {
        console.error('Failed to read audio data.');
        emit('upload-result', { success: false, error: 'Failed to read audio data.' });
        return;
      }
      const base64Audio = String(reader.result).split(',')[1]
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

<style scoped>
.audio-recorder {
  padding: 1.5rem;
  text-align: center;
}

.recorder-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.record-button {
  width: 120px;
  height: 120px;
  border: none;
  border-radius: 50%;
  background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
  color: white;
  cursor: pointer;
  transition: all 0.3s ease;
  box-shadow: 0 4px 20px rgba(76, 175, 80, 0.4);
  position: relative;
  overflow: hidden;
}

.record-button:hover:not(:disabled) {
  transform: scale(1.05);
  box-shadow: 0 6px 25px rgba(76, 175, 80, 0.5);
}

.record-button:disabled {
  cursor: not-allowed;
}

.record-button.recording {
  background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
  box-shadow: 0 4px 20px rgba(244, 67, 54, 0.4);
  animation: recording-pulse 1.5s ease-in-out infinite;
}

@keyframes recording-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.05); }
}

.button-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
}

.mic-icon {
  font-size: 2rem;
}

.recording-animation {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 3px;
  height: 2rem;
}

.wave {
  width: 4px;
  background: white;
  border-radius: 2px;
  animation: wave-animation 1s ease-in-out infinite;
}

.wave-1 {
  height: 20px;
  animation-delay: 0s;
}

.wave-2 {
  height: 30px;
  animation-delay: 0.2s;
}

.wave-3 {
  height: 25px;
  animation-delay: 0.4s;
}

@keyframes wave-animation {
  0%, 100% { transform: scaleY(0.5); }
  50% { transform: scaleY(1); }
}

.button-text {
  font-size: 0.9rem;
  font-weight: 600;
}

.stop-button {
  background: linear-gradient(135deg, #ff5722 0%, #e64a19 100%);
  color: white;
  border: none;
  border-radius: 2rem;
  padding: 0.75rem 1.5rem;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transition: all 0.2s ease;
  box-shadow: 0 2px 10px rgba(255, 87, 34, 0.3);
}

.stop-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 15px rgba(255, 87, 34, 0.4);
}

.stop-icon {
  font-size: 1rem;
}

.recording-info {
  margin-top: 1.5rem;
  padding: 1rem;
  background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
  border-radius: 1rem;
  border: 1px solid #f44336;
}

.countdown {
  font-size: 1.1rem;
  font-weight: 600;
  color: #d32f2f;
  margin-bottom: 0.75rem;
}

.progress-container {
  width: 100%;
}

.progress-bar {
  width: 100%;
  height: 6px;
  background: rgba(244, 67, 54, 0.2);
  border-radius: 3px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #f44336 0%, #d32f2f 100%);
  border-radius: 3px;
  transition: width 0.3s ease;
}

.playback-section {
  margin-top: 1.5rem;
  padding: 1rem;
  background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
  border-radius: 1rem;
  border: 1px solid #4caf50;
}

.playback-info {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  color: #2e7d32;
  font-weight: 500;
}

.file-icon {
  font-size: 1.1rem;
}

.audio-player {
  width: 100%;
  max-width: 300px;
}

@media (max-width: 768px) {
  .audio-recorder {
    padding: 1rem;
  }
  
  .record-button {
    width: 100px;
    height: 100px;
  }
  
  .mic-icon {
    font-size: 1.75rem;
  }
  
  .button-text {
    font-size: 0.8rem;
  }
  
  .stop-button {
    padding: 0.625rem 1.25rem;
    font-size: 0.9rem;
  }
}
</style>
