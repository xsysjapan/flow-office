import { createElement, Fragment } from 'react'
import type { Preview } from '@storybook/react-vite'
import '../src/index.css'
import { Toaster } from '../src/components/ui/sonner'

const preview: Preview = {
  // notify.success(トースト)をstory上でも見えるようにする
  decorators: [(Story) => createElement(Fragment, null, createElement(Story), createElement(Toaster))],
  parameters: {
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },
  },
}

export default preview
