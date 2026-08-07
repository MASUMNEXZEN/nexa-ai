import { BedrockRuntimeClient, ConverseStreamCommand } from "@aws-sdk/client-bedrock-runtime";

const client = new BedrockRuntimeClient({ region: process.env.AWS_REGION || "us-east-1" });

export const handler = awslambda.streamifyResponse(async (event, responseStream, context) => {
    try {
        // 1. Basic Security Check
        const authHeader = event.headers['authorization'] || event.headers['Authorization'];
        const expectedSecret = process.env.PROXY_SECRET; // Set this in Lambda Environment Variables
        
        if (expectedSecret && authHeader !== `Bearer ${expectedSecret}`) {
            responseStream.write(JSON.stringify({ error: "Unauthorized" }));
            responseStream.end();
            return;
        }

        // 2. Parse Incoming NexA Request
        const body = JSON.parse(event.body);
        const modelId = body.model || process.env.BEDROCK_MODEL_ID || "amazon.nova-pro-v1:0"; 
        // Other options: "anthropic.claude-3-5-sonnet-20240620-v1:0"

        // Standardize messages array format for AWS Converse API
        const messages = body.messages.map(msg => ({
            role: msg.role,
            content: [{ text: msg.content }]
        }));

        let systemConfig = [];
        if (body.system) {
            systemConfig = [{ text: body.system }];
        }

        // 3. Command for Bedrock
        const command = new ConverseStreamCommand({
            modelId: modelId,
            messages: messages,
            system: systemConfig,
            inferenceConfig: {
                maxTokens: body.max_tokens || 800,
                temperature: body.temperature || 0.4
            }
        });

        // 4. Stream to Bedrock and pipe back to PHP as SSE
        const bedrockResponse = await client.send(command);

        for await (const chunk of bedrockResponse.stream) {
            if (chunk.contentBlockDelta && chunk.contentBlockDelta.delta && chunk.contentBlockDelta.delta.text) {
                const textChunk = chunk.contentBlockDelta.delta.text;
                // Format exactly like Gemini/OpenAI SSE chunks for NexA
                const ssePayload = JSON.stringify({
                    candidates: [{
                        content: { parts: [{ text: textChunk }] }
                    }]
                });
                responseStream.write(`data: ${ssePayload}\n\n`);
            }
        }

        responseStream.write(`data: [DONE]\n\n`);
        responseStream.end();

    } catch (err) {
        console.error("Bedrock Stream Error:", err);
        // Fallback or error format
        const errorPayload = JSON.stringify({ error: err.message || "Internal Server Error" });
        responseStream.write(`data: ${errorPayload}\n\n`);
        responseStream.end();
    }
});
