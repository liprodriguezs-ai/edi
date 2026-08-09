import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useTicketChat } from '@/modules/ticket/hooks/useTicketChat'
import { useSession } from '@/shared/auth/useSession'
import { MessageSquare, Paperclip, Send, X } from 'lucide-react'
import { type ChangeEvent, type KeyboardEvent, useCallback, useRef, useState } from 'react'
import { TicketChatMessage } from '@/modules/ticket/components/TicketChatMessage'

interface TicketChatProps {
  ticketId: string | null
}

export function TicketChat({ ticketId }: TicketChatProps) {
  const { messages, isLoading, isSending, sendMessage, scrollRef } = useTicketChat(ticketId)
  const { user } = useSession()
  const [messageText, setMessageText] = useState('')
  const [selectedFiles, setSelectedFiles] = useState<File[]>([])
  const fileInputRef = useRef<HTMLInputElement>(null)
  const textareaRef = useRef<HTMLTextAreaElement>(null)

  const handleFileSelect = useCallback((event: ChangeEvent<HTMLInputElement>) => {
    const files = event.target.files
    if (files && files.length > 0) {
      setSelectedFiles((prev) => [...prev, ...Array.from(files)])
    }
    // Limpiar el input para permitir re-seleccionar el mismo archivo
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }, [])

  const handleRemoveFile = useCallback((index: number) => {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index))
  }, [])

  const handleSend = useCallback(async () => {
    if ((!messageText.trim() && selectedFiles.length === 0) || isSending) return
    const text = messageText
    const files = selectedFiles.length > 0 ? [...selectedFiles] : undefined
    setMessageText('')
    setSelectedFiles([])
    await sendMessage(text, files)
  }, [messageText, selectedFiles, isSending, sendMessage])

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLTextAreaElement>) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault()
        void handleSend()
      }
    },
    [handleSend]
  )

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {/* Messages area */}
      <div
        ref={scrollRef}
        className="flex flex-1 flex-col overflow-y-auto px-4 py-3"
      >
        {isLoading ? (
          <ChatSkeleton />
        ) : messages.length === 0 ? (
          <ChatEmptyState />
        ) : (
          <div className="mt-auto flex flex-col gap-3">
            {messages.map((msg) => (
              <TicketChatMessage
                key={msg.id}
                message={msg}
                isOwn={msg.user_id === user?.id}
              />
            ))}
          </div>
        )}
      </div>

      {/* Input area */}
      <div className="shrink-0 border-t bg-background px-3 pb-3 pt-2">
        {/* Vista previa de archivos seleccionados */}
        {selectedFiles.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-1.5">
            {selectedFiles.map((file, index) => (
              <div
                key={`${file.name}-${index}`}
                className="flex items-center gap-1.5 rounded-full border bg-muted/50 px-2.5 py-1 text-xs"
              >
                <Paperclip className="size-3 shrink-0 text-muted-foreground" />
                <span className="max-w-[150px] truncate" title={file.name}>
                  {file.name}
                </span>
                <button
                  type="button"
                  onClick={() => handleRemoveFile(index)}
                  className="ml-0.5 rounded-full p-0.5 transition-colors hover:bg-muted"
                  aria-label={`Eliminar ${file.name}`}
                >
                  <X className="size-3 text-muted-foreground" />
                </button>
              </div>
            ))}
          </div>
        )}

        {/* Chat input bar */}
        <div className="flex items-end gap-1.5 rounded-2xl border bg-muted/30 px-1.5 py-1.5 transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/20">
          {/* Paperclip button */}
          <input
            ref={fileInputRef}
            type="file"
            multiple
            className="hidden"
            onChange={handleFileSelect}
          />
          <Button
            variant="ghost"
            size="icon"
            type="button"
            className="size-8 shrink-0 rounded-full text-muted-foreground hover:text-foreground"
            onClick={() => fileInputRef.current?.click()}
            disabled={isSending || !ticketId}
          >
            <Paperclip className="size-4" />
          </Button>

          {/* Textarea styled as inline input */}
          <textarea
            ref={textareaRef}
            value={messageText}
            onChange={(e) => setMessageText(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Escribe tu mensaje..."
            rows={1}
            className="max-h-24 min-h-[32px] flex-1 resize-none bg-transparent py-1.5 text-sm leading-snug outline-none placeholder:text-muted-foreground/70"
            disabled={isSending || !ticketId}
          />

          {/* Send button */}
          <Button
            size="icon"
            type="button"
            className="size-8 shrink-0 rounded-full"
            onClick={() => void handleSend()}
            disabled={isSending || (!messageText.trim() && selectedFiles.length === 0)}
          >
            <Send className="size-4" />
          </Button>
        </div>

        {/* Keyboard shortcut hint */}
        <p className="mt-1.5 text-center text-[10px] text-muted-foreground/50">
          Enter para enviar · Shift+Enter para salto de línea
        </p>
      </div>
    </div>
  )
}

function ChatEmptyState() {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-3">
      <div className="flex size-12 items-center justify-center rounded-full bg-muted">
        <MessageSquare className="size-6 text-muted-foreground" />
      </div>
      <div className="text-center">
        <p className="text-sm font-medium text-muted-foreground">
          Sin mensajes todavia
        </p>
        <p className="mt-0.5 text-xs text-muted-foreground/60">
          Envia el primer mensaje para iniciar la conversacion
        </p>
      </div>
    </div>
  )
}

function ChatSkeleton() {
  return (
    <div className="mt-auto flex flex-col gap-3">
      {Array.from({ length: 3 }).map((_, i) => (
        <div
          key={i}
          className={`flex items-end gap-2 ${i % 2 === 0 ? 'flex-row' : 'flex-row-reverse'}`}
        >
          {i % 2 === 0 && <Skeleton className="size-6 shrink-0 rounded-full" />}
          <div className="space-y-1">
            {i % 2 === 0 && <Skeleton className="h-3 w-16" />}
            <Skeleton className="h-10 w-44 rounded-2xl" />
            <Skeleton className="h-2 w-12" />
          </div>
        </div>
      ))}
    </div>
  )
}
