import { defineConfig } from '@hey-api/openapi-ts'

export default defineConfig({
  input: '/app/openapi-spec/openapi.yaml',
  output: {
    path: 'src/lib/api/generated',
    postProcess: ['prettier'],
  },
  plugins: [
    '@hey-api/client-fetch',
    '@hey-api/typescript',
    '@hey-api/sdk',
  ],
})
