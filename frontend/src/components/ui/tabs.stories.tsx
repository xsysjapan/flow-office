import type { Meta, StoryObj } from '@storybook/react-vite'
import { Tabs, TabsContent, TabsList, TabsTrigger } from './tabs'

const meta = {
  title: 'UI/Tabs',
  component: Tabs,
  tags: ['autodocs'],
} satisfies Meta<typeof Tabs>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {
  render: () => (
    <Tabs defaultValue="a" className="w-96">
      <TabsList>
        <TabsTrigger value="a">まとめて設定</TabsTrigger>
        <TabsTrigger value="b">曜日ごとに設定</TabsTrigger>
      </TabsList>
      <TabsContent value="a">まとめて設定の内容</TabsContent>
      <TabsContent value="b">曜日ごとに設定の内容</TabsContent>
    </Tabs>
  ),
}
