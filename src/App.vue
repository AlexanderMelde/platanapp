<script setup lang="ts">
import { ref } from 'vue'
import WelcomeHeader from './components/WelcomeHeader.vue'
import SensorWidget from './components/SensorWidget.vue'
import ChatBubble from './components/ChatBubble.vue'
import StatusIndicator from './components/StatusIndicator.vue'
import AudioRecorder from './components/AudioRecorder.vue'
import ModelSelector from './components/ModelSelector.vue'
import FooterInfo from './components/FooterInfo.vue'
import { onMounted } from 'vue'

import { Device } from '@capacitor/device';

const logDeviceInfo = async () => {
  const info = await Device.getInfo();

  console.log(info);
};

const hasAudio = ref<boolean>(false)
const hasText = ref<boolean>(false)
const transcript  = ref<string>("")
const hasResponse = ref<boolean>(false)
const modelResponse = ref<string>("")
const audioUrl = ref<string | null>(null)
const model = ref<string>('granite3.3:2b') // Default model
const chatHistory = ref<Array<{type: 'user' | 'assistant', message: string, audioUrl?: string}>>([])


const convertUrl = 'https://llama.ok-lab-karlsruhe.de/platane/php/convert.php'

const whisperUrl = 'https://llama.ok-lab-karlsruhe.de/platane/php/whisper.php'

const modelUrl = 'https://llama.ok-lab-karlsruhe.de/platane/php/plataChat.php'


async function handleUploadResult(payload: { success: boolean; data?: { filename: string } }): Promise<void> {
  // Catch the emitted event and handle the result here
  if (payload.success) {
    console.log('APP: Upload successful:', payload.data?.filename)
    hasAudio.value = true
    try {
      const response = await fetch(convertUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ filename: payload.data?.filename })
      })
      const data = await response.json()
      console.log('Converter response:', data)
      if (data.status !== "ok") {
        throw new Error('Convert failed ' + data.status)
      }

      try {
        const response = await fetch(whisperUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ filename: data.filename }) // Specify the model here
        })
        const data2 = await response.json()
        console.log('Whisper response:', data2)
        if (data2.status !== "ok") {
          throw new Error('Whisper failed ' + data2.status)
        }
        transcript.value = data2.text
        hasText.value = true
        
        // Add user message to chat history
        chatHistory.value.push({
          type: 'user',
          message: transcript.value
        })

        try {
          const response = await fetch(modelUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ text: transcript.value, model: model.value }) // Specify the model here
          })
          const data3 = await response.json()
          console.log('Model response available') // , data3)
          if (data3.status !== "ok") {
            throw new Error('Model failed ' + data3.status)
          }
          console.log('Audio from ' + data3.synth) // , data3)
          modelResponse.value = data3.text
          hasResponse.value = true

          if (data3.audio) {
            const audioBase64 = data3.audio
            const audioBlob = new Blob([Uint8Array.from(atob(audioBase64), c => c.charCodeAt(0))], { type: 'audio/wav' })
            audioUrl.value = URL.createObjectURL(audioBlob)
            
            // Add assistant message to chat history
            chatHistory.value.push({
              type: 'assistant',
              message: modelResponse.value,
              audioUrl: audioUrl.value
            })
          }
        } catch (error) {
          console.error('Error fetching model response:', error)
          modelResponse.value = "Error fetching model response."
          chatHistory.value.push({
            type: 'assistant',
            message: "Sorry, I encountered an error while processing your request."
          })
        }


      } catch (error) {
        console.error('Error fetching whisper response:', error)
        modelResponse.value = "Error fetching whisper response."
        chatHistory.value.push({
          type: 'assistant',
          message: "Sorry, I couldn't understand your audio. Please try again."
        })
      }


    } catch (error) {
      console.error('Error fetching model response:', error)
      modelResponse.value = "Error fetching model response."
      chatHistory.value.push({
        type: 'assistant',
        message: "Sorry, I encountered a technical issue. Please try again."
      })
    }


    // You can add further logic here
  } else {
    console.error('Upload failed')
  }
}

const resetAudio = () => {
  hasAudio.value = false
  hasText.value = false
  hasResponse.value = false
  transcript.value  = ""
  modelResponse.value = ""
  audioUrl.value = null
  console.log('Audio reset')
}

const playAudio = (audioUrl: string) => {
  const audio = new Audio(audioUrl)
  audio.play()
}

onMounted(async () => {
  await logDeviceInfo()
})

</script>

<template>
  <div class="app-container">
    <WelcomeHeader />
    
    <div class="main-content">
      <SensorWidget />
      
      <ModelSelector v-model="model" />
      
      <div class="chat-area">
        <div v-if="chatHistory.length === 0" class="welcome-message">
          <div class="welcome-icon">👋</div>
          <h3>Hello! I'm Papperlapp</h3>
          <p>I'm a friendly plane tree who loves to chat about AI and environmental protection. Press the microphone button below to start our conversation!</p>
        </div>
        
        <div class="chat-messages">
          <ChatBubble
            v-for="(message, index) in chatHistory"
            :key="index"
            :type="message.type"
            :message="message.message"
            :audioUrl="message.audioUrl"
            @play-audio="playAudio"
          />
        </div>
        
        <StatusIndicator
          :visible="hasAudio && !hasText"
          type="transcribing"
          message="Audio is being transcribed..."
        />
        
        <StatusIndicator
          :visible="hasText && !hasResponse"
          type="thinking"
          message="Papperlapp is thinking about your question..."
        />
      </div>
      
      <AudioRecorder 
        @upload-result="handleUploadResult" 
        @reset="resetAudio" 
      />
    </div>
    
    <FooterInfo />
  </div>
</template>

<style scoped>
.app-container {
  min-height: 100vh;
  background: linear-gradient(135deg, #f0f8f0 0%, #e8f5e8 100%);
  display: flex;
  flex-direction: column;
}

.main-content {
  flex: 1;
  max-width: 800px;
  margin: 0 auto;
  padding: 0 1rem 2rem;
  width: 100%;
}

.chat-area {
  background: white;
  border-radius: 1.5rem;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
  min-height: 400px;
}

.welcome-message {
  text-align: center;
  padding: 2rem 1rem;
  color: #2d5016;
}

.welcome-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
}

.welcome-message h3 {
  font-size: 1.5rem;
  margin: 0 0 1rem;
  font-weight: 600;
}

.welcome-message p {
  font-size: 1rem;
  line-height: 1.6;
  margin: 0;
  max-width: 500px;
  margin: 0 auto;
}

.chat-messages {
  max-height: 500px;
  overflow-y: auto;
  padding-right: 0.5rem;
}

.chat-messages::-webkit-scrollbar {
  width: 6px;
}

.chat-messages::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb {
  background: #c1c1c1;
  border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
  background: #a8a8a8;
}

@media (max-width: 768px) {
  .main-content {
    padding: 0 0.75rem 1.5rem;
  }
  
  .chat-area {
    padding: 1rem;
    border-radius: 1rem;
    min-height: 300px;
  }
  
  .welcome-message {
    padding: 1.5rem 0.75rem;
  }
  
  .welcome-icon {
    font-size: 2.5rem;
  }
  
  .welcome-message h3 {
    font-size: 1.25rem;
  }
  
  .welcome-message p {
    font-size: 0.9rem;
  }
  
  .chat-messages {
    max-height: 400px;
  }
}
</style>
